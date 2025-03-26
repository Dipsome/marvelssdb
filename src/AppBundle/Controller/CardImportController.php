<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use AppBundle\Entity\Cardset;
use AppBundle\Entity\Pack;
use AppBundle\Entity\Card;
use AppBundle\Entity\Faction;
use AppBundle\Entity\Type;
use AppBundle\Entity\Cardsettype;
use AppBundle\Entity\PackOwnership;

class CardImportController extends AbstractController
{
    /**
     * @Route("/import_cards", name="import_cards")
     */
    public function importCardsAction(Request $request): Response
    {
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->clear();
        $duplicates = [];
        $cardsToLink = [];

        $form = $this->createFormBuilder()
            ->add('pack_file', FileType::class, ['label' => 'Pack File (.json)', 'required' => true])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $packFile */
            $packFile = $form->get('pack_file')->getData();

            try {
                if ($packFile->getClientOriginalExtension() !== 'json') {
                    throw new \Exception('Only JSON files are allowed.');
                }

                $jsonContent = file_get_contents($packFile->getPathname());
                $data = $this->getDataFromString($jsonContent);

                if (isset($data[0]['pack_code'])) {
                    $this->importCardsFromJsonData($data, $entityManager, $duplicates, $cardsToLink);
                } elseif (isset($data[0]['cgdb_id'])) {
                    $this->importPacksJsonData($data, $entityManager);
                } elseif (isset($data[0]['card_set_type_code'])) {
                    $this->importCardSetsJsonData($data, $entityManager);
                } else {
                    throw new \Exception('Unknown JSON format. Expected card, pack, or cardset data.');
                }

                if (!empty($duplicates)) {
                    $this->processDuplicates($duplicates, $entityManager);
                }

                $this->addFlash('debug', 'Cards to link: ' . json_encode($cardsToLink));

                $this->linkCardsBySuffix($cardsToLink, $entityManager);

                $entityManager->flush();
                $this->addFlash('success', 'Data imported successfully into the database!');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Error: ' . $e->getMessage());
            }
        }

        return $this->render('@App/Default/import_cards.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    private function ensurePackExists($packCode, $entityManager)
{
    $pack = $entityManager->getRepository('AppBundle\\Entity\\Pack')
        ->findOneBy(['code' => $packCode]);

    if ($pack) {
        $user = $this->getUser();
        if ($user && $this->isGranted('ROLE_CREATOR') && !$this->isGranted('ROLE_ADMIN')) {
            // Ensure the user is managed by fetching it from the database
            $managedUser = $entityManager->getRepository('AppBundle\\Entity\\User')
                ->findOneBy(['id' => $user->getId()]);
            if (!$managedUser) {
                throw new \Exception("Authenticated user {$user->getUsername()} (ID: {$user->getId()}) not found in database.");
            }
            $ownership = $entityManager->getRepository('AppBundle\\Entity\\PackOwnership')
                ->findOneBy(['user' => $managedUser, 'pack' => $pack]);
            if (!$ownership) {
                throw new \Exception("You do not have ownership of pack [$packCode] and cannot modify it.");
            }
        }
    } else {
        $pack = new Pack();
        $pack->setCode($packCode);
        $pack->setName($packCode);
        $pack->setPosition(1);
        $pack->setSize(1);
        $pack->setDateCreation(new \DateTime());
        $pack->setDateUpdate(new \DateTime());
        $entityManager->persist($pack);
        $entityManager->flush();
        $this->addFlash('debug', "New pack created with ID: " . $pack->getId());

        $user = $this->getUser();
        if ($user) {
            $this->addFlash('debug', "User authenticated: ID {$user->getId()}, Roles: " . implode(', ', $user->getRoles()));
            if ($this->isGranted('ROLE_CREATOR') && !$this->isGranted('ROLE_ADMIN')) {
                try {
                    // Fetch the managed user from the database
                    $managedUser = $entityManager->getRepository('AppBundle\\Entity\\User')
                        ->findOneBy(['id' => $user->getId()]);
                    if (!$managedUser) {
                        throw new \Exception("Authenticated user {$user->getUsername()} (ID: {$user->getId()}) not found in database.");
                    }

                    $packOwnership = new PackOwnership();
                    $packOwnership->setUser($managedUser);
                    $packOwnership->setPack($pack);
                    $entityManager->persist($packOwnership);
                    $entityManager->flush();
                    $this->addFlash('info', "PackOwnership created for pack [$packCode] and user [{$managedUser->getId()}] with ID: " . $packOwnership->getId());
                } catch (\Exception $e) {
                    $this->addFlash('error', "Failed to create PackOwnership: " . $e->getMessage());
                    if (!$entityManager->isOpen()) {
                        $entityManager = $this->getDoctrine()->getManager();
                    }
                }
            } else {
                $this->addFlash('debug', "User has roles: " . implode(', ', $user->getRoles()) . " - Not creating ownership.");
            }
        } else {
            $this->addFlash('debug', "No user authenticated - Skipping PackOwnership creation.");
        }
    }

    return $pack;
}

    private function ensureFactionExists(string $factionCode, $entityManager): Faction
    {
        $faction = $entityManager->getRepository('AppBundle\\Entity\\Faction')
            ->findOneBy(['code' => $factionCode]);

        if (!$faction) {
            $faction = new Faction();
            $faction->setCode($factionCode);
            $faction->setName($factionCode);
            $faction->setIsPrimary(false);
            $entityManager->persist($faction);
            $entityManager->flush();
        }

        return $faction;
    }

    private function ensureCardsetExists(string $setCode, $entityManager): Cardset
    {
        $cardset = $entityManager->getRepository('AppBundle\\Entity\\Cardset')
            ->findOneBy(['code' => $setCode]);

        if (!$cardset) {
            $cardset = new Cardset();
            $cardset->setCode($setCode);
            $cardset->setName($setCode);

            $defaultCardsetType = $entityManager->getRepository('AppBundle\\Entity\\Cardsettype')
                ->findOneBy(['code' => 'default']);
            if (!$defaultCardsetType) {
                $defaultCardsetType = new Cardsettype();
                $defaultCardsetType->setCode('default');
                $defaultCardsetType->setName('Default Cardset Type');
                $entityManager->persist($defaultCardsetType);
                $entityManager->flush();
            }
            $cardset->setCardSetType($defaultCardsetType);

            try {
                $entityManager->persist($cardset);
                $entityManager->flush();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                $cardset = $entityManager->getRepository('AppBundle\\Entity\\Cardset')
                    ->findOneBy(['code' => $setCode]);
                if (!$cardset) {
                    throw new \Exception("Failed to create or retrieve Cardset with code [$setCode]");
                }
            }
        }

        return $cardset;
    }

    private function ensureTypeExists(string $typeCode, $entityManager): Type
    {
        $type = $entityManager->getRepository('AppBundle\\Entity\\Type')
            ->findOneBy(['code' => $typeCode]);

        if (!$type) {
            $type = new Type();
            $type->setCode($typeCode);
            $type->setName($typeCode);
            $entityManager->persist($type);
            $entityManager->flush();
        }

        return $type;
    }

    protected function importCardSetsJsonData(array $cardsData, $entityManager): void
    {
        foreach ($cardsData as $data) {
            $cardset = $this->getEntityFromData('AppBundle\\Entity\\Cardset', $data, [
                'code',
                'name'
            ], [
                'card_set_type_code'
            ], [], $entityManager);

            if ($cardset) {
                $entityManager->persist($cardset);
            }
        }
    }

    protected function importPacksJsonData(array $packsData, $entityManager): void
    {
        foreach ($packsData as $data) {
            $pack = $this->getEntityFromData('AppBundle\\Entity\\Pack', $data, [
                'code',
                'name',
                'position',
                'size',
                'date_release'
            ], [
                'pack_type_code'
            ], [
                'cgdb_id'
            ], $entityManager);

            if ($pack) {
                $entityManager->persist($pack);
            }
        }
    }

    protected function importCardsFromJsonData(array $cardsData, $entityManager, array &$duplicates, array &$cardsToLink): void
    {
        foreach ($cardsData as $data) {
            if (isset($data['duplicate_of']) && !isset($data['name'])) {
                $duplicates[] = $data;
                continue;
            }

            $card = $this->getEntityFromData('AppBundle\\Entity\\Card', $data, [
                'code',
                'position',
                'quantity',
                'name'
            ], [
                'faction_code',
                'faction2_code',
                'pack_code',
                'type_code',
                'subtype_code',
                'set_code',
                'back_card_code',
                'front_card_code'
            ], [
                'deck_limit',
                'set_position',
                'illustrator',
                'flavor',
                'traits',
                'text',
                'cost',
                'resource_physical',
                'resource_mental',
                'resource_energy',
                'resource_wild',
                'restrictions',
                'deck_options',
                'deck_requirements',
                'meta',
                'subname',
                'back_text',
                'back_flavor',
                'back_name',
                'double_sided',
                'is_unique',
                'hidden',
                'permanent',
                'errata',
                'octgn_id',
                'attack',
                'attack_cost',
                'attack_star',
                'thwart',
                'thwart_cost',
                'thwart_star',
                'defense',
                'defense_star',
                'recover',
                'recover_star',
                'health',
                'health_star',
                'hand_size',
                'base_threat',
                'base_threat_fixed',
                'boost',
                'boost_star',
                'scheme',
                'scheme_star'
            ], $entityManager);

            if ($card) {
                if ($card->getName()) {
                    $card->setRealName($card->getName());
                }
                if ($card->getTraits()) {
                    $card->setRealTraits($card->getTraits());
                }
                if ($card->getText()) {
                    $card->setRealText($card->getText());
                }
                $entityManager->persist($card);
                $entityManager->flush(); // Ensure card has an ID

                $code = $data['code'];
                $lastChar = substr($code, -1); // Get the last character
                $isSuffixCard = in_array($lastChar, ['a', 'b']);
                $hasBackLink = isset($data['back_link']);

                // Debug: Log suffix check
                $this->addFlash('info', "Checking card: $code, Last char: $lastChar, Is suffix: " . ($isSuffixCard ? 'yes' : 'no') . ", Has back_link: " . ($hasBackLink ? 'yes' : 'no'));

                if ($isSuffixCard || $hasBackLink) {
                    $cardsToLink[] = [
                        'card' => $card,
                        'code' => $code,
                        'back_link' => $data['back_link'] ?? null
                    ];
                }
            }
        }
    }

    protected function processDuplicates(array $duplicates, $entityManager): void
    {
        foreach ($duplicates as $duplicateData) {
            $duplicateOfCode = $duplicateData['duplicate_of'];
            $duplicateOf = $entityManager->getRepository('AppBundle\\Entity\\Card')
                ->findOneBy(['code' => $duplicateOfCode]);

            if (!$duplicateOf) {
                throw new \Exception("Duplicate card references non-existent card [$duplicateOfCode]");
            }

            $existingCard = $entityManager->getRepository('AppBundle\\Entity\\Card')
                ->findOneBy(['code' => $duplicateData['code']]);

            if ($existingCard) {
                if ($existingCard->getDuplicateOf() !== $duplicateOf) {
                    $existingCard->setDuplicateOf($duplicateOf);
                    $entityManager->persist($existingCard);
                }
                continue;
            }

            $newCard = new Card();
            // Booleans with default 0 when not mentioned
            $newCard->setIsUnique($duplicateData['is_unique'] ?? 0);
            $newCard->setHidden($duplicateData['hidden'] ?? 0);
            $newCard->setPermanent($duplicateData['permanent'] ?? 0);
            // Booleans with default null when not mentioned
            $newCard->setDoubleSided($duplicateData['double_sided'] ?? null);
            $newCard->setAttackStar($duplicateData['attack_star'] ?? null);
            $newCard->setThwartStar($duplicateData['thwart_star'] ?? null);
            $newCard->setDefenseStar($duplicateData['defense_star'] ?? null);
            $newCard->setHealthStar($duplicateData['health_star'] ?? null);
            $newCard->setRecoverStar($duplicateData['recover_star'] ?? null);
            $newCard->setSchemeStar($duplicateData['scheme_star'] ?? null);
            $newCard->setBoostStar($duplicateData['boost_star'] ?? null);
            $newCard->setThreatStar($duplicateData['threat_star'] ?? null);
            $newCard->setEscalationThreatStar($duplicateData['escalation_threat_star'] ?? null);
            $newCard->setBaseThreatFixed($duplicateData['base_threat_fixed'] ?? null);
            $newCard->setEscalationThreatFixed($duplicateData['escalation_threat_fixed'] ?? null);
            $newCard->setThreatFixed($duplicateData['threat_fixed'] ?? null);
            $newCard->setHealthPerHero($duplicateData['health_per_hero'] ?? null);

            $this->copyKeyToEntity($newCard, 'AppBundle\\Entity\\Card', $duplicateData, 'code', true, $entityManager);
            $this->copyKeyToEntity($newCard, 'AppBundle\\Entity\\Card', $duplicateData, 'position', true, $entityManager);
            $this->copyKeyToEntity($newCard, 'AppBundle\\Entity\\Card', $duplicateData, 'quantity', true, $entityManager);

            $newCard->setName($duplicateOf->getName());
            $newCard->setRealName($duplicateOf->getRealName());

            $optionalKeys = [
                'deck_limit', 'set_position', 'illustrator', 'flavor', 'traits', 'text', 'cost',
                'resource_physical', 'resource_mental', 'resource_energy', 'resource_wild',
                'restrictions', 'deck_options', 'deck_requirements', 'meta', 'subname',
                'back_text', 'back_flavor', 'back_name', 'double_sided', 'is_unique', 'hidden',
                'permanent', 'errata', 'octgn_id', 'attack', 'attack_cost', 'attack_star',
                'thwart', 'thwart_cost', 'thwart_star', 'defense', 'defense_star', 'recover',
                'recover_star', 'health', 'health_star', 'hand_size', 'base_threat',
                'base_threat_fixed', 'boost', 'boost_star', 'scheme', 'scheme_star'
            ];

            foreach ($optionalKeys as $key) {
                $camelKey = $this->snakeToCamel($key);
                $getter = 'get' . $camelKey;
                $setter = 'set' . $camelKey;

                if (isset($duplicateData[$key])) {
                    $this->copyKeyToEntity($newCard, 'AppBundle\\Entity\\Card', $duplicateData, $key, false, $entityManager);
                } elseif ($duplicateOf->$getter()) {
                    $newCard->$setter($duplicateOf->$getter());
                }
            }

            if (isset($duplicateData['pack_code'])) {
                $pack = $this->ensurePackExists($duplicateData['pack_code'], $entityManager);
                $newCard->setPack($pack);
            } elseif ($duplicateOf->getPack()) {
                $newCard->setPack($duplicateOf->getPack());
            }

            $newCard->setDuplicateOf($duplicateOf);
            $entityManager->persist($newCard);
        }
    }

    protected function linkCardsBySuffix(array $cardsToLink, $entityManager): void
    {
        $processed = [];
        foreach ($cardsToLink as $index => $entry) {
            $card = $entry['card'];
            $code = $entry['code'];
            $backLink = $entry['back_link'];

            if (in_array($code, $processed)) {
                continue; // Skip if already processed
            }

            if ($backLink) {
                $linkedCard = $entityManager->getRepository('AppBundle\\Entity\\Card')
                    ->findOneBy(['code' => $backLink]);
                if ($linkedCard) {
                    $card->setLinkedTo($linkedCard); // Unidirectional: a -> b
                    $entityManager->persist($card);
                    $processed[] = $code;
                    $this->addFlash('info', "Linked $code to $backLink via back_link");
                } else {
                    $this->addFlash('info', "No linked card found for back_link: $backLink");
                }
            } else {
                $lastChar = substr($code, -1);
                if (in_array($lastChar, ['a', 'b'])) {
                    $baseCode = substr($code, 0, -1);
                    $targetSuffix = ($lastChar === 'a') ? 'b' : 'a';
                    $targetCode = $baseCode . $targetSuffix;

                    $linkedCard = $entityManager->getRepository('AppBundle\\Entity\\Card')
                        ->findOneBy(['code' => $targetCode]);
                    if ($linkedCard && $lastChar === 'a') { // Only link if suffix is 'a'
                        $card->setLinkedTo($linkedCard); // Unidirectional: a -> b
                        $entityManager->persist($card);
                        $processed[] = $code;
                        $this->addFlash('info', "Linked $code to $targetCode via suffix");
                    } else if ($lastChar === 'b') {
                        $this->addFlash('info', "Skipped linking $code to $targetCode (b does not link back)");
                    } else {
                        $this->addFlash('info', "No linked card found for $code -> $targetCode");
                    }
                }
            }

            // Batch flush and clear every 50 links
            if ($index % 50 === 0 && $index > 0) {
                $entityManager->flush();
                $entityManager->clear();
                $this->addFlash('info', "Linked $index cards, cleared memory");
            }
        }
    }

    protected function getEntityFromData(string $entityName, array $data, array $mandatoryKeys, array $foreignKeys, array $optionalKeys, $entityManager)
    {
        if (!isset($data['code'])) {
            throw new \Exception("Missing key [code] in " . json_encode($data));
        }

        $entity = $entityManager->getRepository($entityName)->findOneBy(['code' => $data['code']]);
        if (!$entity) {
            $entity = new $entityName();
        }

        $orig = $entity->serialize();

        foreach ($mandatoryKeys as $key) {
            $this->copyKeyToEntity($entity, $entityName, $data, $key, true, $entityManager);
        }

        foreach ($optionalKeys as $key) {
            $this->copyKeyToEntity($entity, $entityName, $data, $key, false, $entityManager);
        }

        foreach ($foreignKeys as $key) {
            $foreignEntityShortName = ucfirst(str_replace('_code', '', $key));
            if ($key === 'front_card_code' || $key === 'back_card_code') {
                $foreignEntityShortName = 'Card';
            } elseif ($key === 'set_code') {
                $foreignEntityShortName = 'Cardset';
            } elseif ($key === 'pack_type_code') {
                $foreignEntityShortName = 'Packtype';
            } elseif ($key === 'card_set_type_code') {
                $foreignEntityShortName = 'Cardsettype';
            } elseif ($key === 'type_code') {
                $foreignEntityShortName = 'Type';
            }

            if (!isset($data[$key])) {
                if (in_array($key, ['faction2_code', 'subtype_code', 'set_code', 'back_card_code', 'front_card_code'])) {
                    continue;
                }
                throw new \Exception("Missing key [$key] in " . json_encode($data));
            }

            $foreignCode = $data[$key];
            if (!$foreignCode) {
                continue;
            }

            if ($key === 'pack_code') {
                $foreignEntity = $this->ensurePackExists($foreignCode, $entityManager);
            } elseif ($key === 'faction_code' || $key === 'faction2_code') {
                $foreignEntity = $this->ensureFactionExists($foreignCode, $entityManager);
            } elseif ($key === 'set_code') {
                $foreignEntity = $this->ensureCardsetExists($foreignCode, $entityManager);
            } elseif ($key === 'type_code') {
                $foreignEntity = $this->ensureTypeExists($foreignCode, $entityManager);
            } else {
                $foreignEntity = $entityManager->getRepository("AppBundle\\Entity\\$foreignEntityShortName")
                    ->findOneBy(['code' => $foreignCode]);
                if (!$foreignEntity) {
                    throw new \Exception("Invalid code [$foreignCode] for key [$key] in " . json_encode($data));
                }
            }

            $getter = 'get' . $foreignEntityShortName;
            $setter = 'set' . $foreignEntityShortName;

            if (!$entity->$getter() || $entity->$getter()->getId() !== $foreignEntity->getId()) {
                $entity->$setter($foreignEntity);
            }
        }

        if ($entity->serialize() !== $orig) {
            return $entity;
        }

        return null;
    }

    protected function copyKeyToEntity($entity, string $entityName, array $data, string $key, bool $isMandatory = true, $entityManager): void
    {
        $booleanFieldsWithDefaultZero = ['is_unique', 'hidden', 'permanent'];
        $booleanFieldsWithDefaultNull = [
            'double_sided', 'attack_star', 'thwart_star', 'defense_star', 'health_star',
            'recover_star', 'scheme_star', 'boost_star', 'threat_star', 'escalation_threat_star',
            'base_threat_fixed', 'escalation_threat_fixed', 'threat_fixed', 'health_per_hero'
        ];

        if (!isset($data[$key])) {
            if ($isMandatory) {
                throw new \Exception("Missing key [$key] in " . json_encode($data));
            }
            // Set default based on boolean field type
            if (in_array($key, $booleanFieldsWithDefaultZero)) {
                $value = 0; // Default to false (0) for is_unique, hidden, permanent
            } elseif (in_array($key, $booleanFieldsWithDefaultNull)) {
                $value = null; // Default to null for other booleans
            } else {
                $value = null; // Default to null for non-boolean optional fields
            }
        } else {
            $value = $data[$key];
        }

        if (in_array($key, ['deck_requirements', 'deck_options', 'meta']) && $value) {
            $value = json_encode($value);
        }

        $fieldName = $this->snakeToCamel($key);
        $setter = 'set' . $fieldName;

        if (method_exists($entity, $setter)) {
            $entity->$setter($value);
        } else {
            throw new \Exception("Setter method [$setter] does not exist in entity $entityName for key [$key]");
        }
    }

    protected function getDataFromString(string $string): array
    {
        $data = json_decode($string, true);
        if ($data === null) {
            throw new \Exception("Invalid JSON data (error code " . json_last_error() . ")");
        }
        return $data;
    }

    private function snakeToCamel($snake)
    {
        $parts = explode('_', $snake);
        return implode('', array_map('ucfirst', $parts));
    }
}