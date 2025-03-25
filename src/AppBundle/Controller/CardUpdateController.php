<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Card;
use AppBundle\Entity\PackOwnership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class CardUpdateController extends AbstractController
{
    /**
     * @Route("/cards/update/{code}", name="cards_update")
     */
    public function updateCardAction(Request $request, string $code, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(['ROLE_CREATOR', 'ROLE_ADMIN'], null, 'Only Creators and Admins can update cards.');

        $card = $em->getRepository(Card::class)->findOneBy(['code' => $code]);
        if (!$card) {
            throw $this->createNotFoundException("Card with code $code not found.");
        }

        // Check pack ownership for Creators
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_ADMIN')) {
            $pack = $card->getPack();
            if (!$pack) {
                throw $this->createAccessDeniedException('Card has no associated pack.');
            }
            $ownershipRepo = $em->getRepository(PackOwnership::class);
            $ownership = $ownershipRepo->findOneBy([
                'userId' => $user->getId(),
                'packId' => $pack->getId(),
            ]);
            if (!$ownership) {
                throw $this->createAccessDeniedException('You can only update cards in packs you own.');
            }
        }

        $form = $this->createFormBuilder($card)
            ->add('name', TextType::class)
            ->add('text', TextType::class)
            ->add('save', SubmitType::class, ['label' => 'Update Card'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($card);
            $em->flush();
            $this->addFlash('success', 'Card updated successfully!');
            return $this->redirectToRoute('cards_update', ['code' => $code]);
        }

        return $this->render('@App/Default/update_card.html.twig', [
            'form' => $form->createView(),
            'card' => $card,
        ]);
    }
}