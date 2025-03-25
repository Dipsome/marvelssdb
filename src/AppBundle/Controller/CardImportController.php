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
                    $this->importCardsFromJsonData($data, $entityManager, $duplicates);
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

    private function ensurePackExists(string $packCode, $entityManager): Pack
    {
        $pack = $entityManager->getRepository('AppBundle\\Entity\\Pack')
            ->findOneBy(['code' => $packCode]);

        if (!$pack) {
            $pack = new Pack();
            $pack->setCode($packCode);
            $pack->setName($packCode);
            $pack->setPosition(1);
            $pack->setSize(1);
            $pack->setDateCreation(new \DateTime());
            $pack->setDateUpdate(new \DateTime());
            $entityManager->persist($pack);
            $entityManager->flush();
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

            // Ensure a default Cardsettype exists
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

    protected function importCardsFromJsonData(array $cardsData, $entityManager, array &$duplicates): void
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
            $newCard->setIsUnique(false);
            $newCard->setHidden(false);
            $newCard->setPermanent(false);
            $newCard->setDoubleSided(false);
            $newCard->setAttackStar(false);
            $newCard->setThwartStar(false);
            $newCard->setDefenseStar(false);
            $newCard->setHealthStar(false);
            $newCard->setRecoverStar(false);
            $newCard->setSchemeStar(false);
            $newCard->setBoostStar(false);
            $newCard->setThreatStar(false);
            $newCard->setEscalationThreatStar(false);
            $newCard->setBaseThreatFixed(false);
            $newCard->setEscalationThreatFixed(false);
            $newCard->setThreatFixed(false);
            $newCard->setHealthPerHero(false);

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
        $booleanFields = [
            'is_unique', 'hidden', 'permanent', 'double_sided', 'attack_star', 'thwart_star',
            'defense_star', 'health_star', 'recover_star', 'scheme_star', 'boost_star',
            'threat_star', 'escalation_threat_star', 'base_threat_fixed', 'escalation_threat_fixed',
            'threat_fixed', 'health_per_hero'
        ];

        if (!isset($data[$key])) {
            if ($isMandatory) {
                throw new \Exception("Missing key [$key] in " . json_encode($data));
            }
            $value = in_array($key, $booleanFields) ? false : null;
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