<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Form\ReservationType;
use App\Repository\EventRepository;
use App\Repository\ReservationRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/events')]
class EventController extends AbstractController
{
    #[Route('', name: 'app_events')]
    public function index(EventRepository $eventRepository): Response
    {
        return $this->render('event/index.html.twig', [
            'events' => $eventRepository->findAll(),
        ]);
    }

    #[Route('/{id}', name: 'app_event_show', requirements: ['id' => '\d+'])]
    public function show(
        int $id,
        EventRepository $eventRepository,
        ReservationRepository $reservationRepository,
        Request $request,
        MailerInterface $mailer
    ): Response {
        $event = $eventRepository->find($id);
        if (!$event) {
            throw $this->createNotFoundException('Événement non trouvé');
        }

        // ── PROTECTION RÉSERVATION ──────────────────────────────────────
        // Si le formulaire est soumis (POST) sans session Symfony active,
        // on redirige vers la page de connexion avec un message d'erreur.
        // Cela couvre le cas où un utilisateur non connecté contourne
        // l'interface pour soumettre directement le formulaire.
        if ($request->isMethod('POST') && !$this->getUser()) {
            $this->addFlash('error', 'Vous devez être connecté pour effectuer une réservation.');
            return $this->redirectToRoute('app_login');
        }
        // ───────────────────────────────────────────────────────────────

        $reservation = new Reservation();
        $form = $this->createForm(ReservationType::class, $reservation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            if ($event->getAvailableSeats() <= 0) {
                $this->addFlash('error', 'Plus de places disponibles pour cet événement.');
                return $this->redirectToRoute('app_event_show', ['id' => $id]);
            }

            $reservation->setEvent($event);
            if ($this->getUser()) {
                $reservation->setUser($this->getUser());
            }

            $reservationRepository->save($reservation);

            // ===== ENVOI EMAIL DE CONFIRMATION =====
            try {
                $email = (new TemplatedEmail())
                    ->from('rahmachkel@gmail.com')
                    ->to($reservation->getEmail())
                    ->subject('Confirmation de réservation - ' . $event->getTitle())
                    ->htmlTemplate('emails/reservation_confirmation.html.twig')
                    ->context([
                        'reservation' => $reservation,
                        'event'       => $event,
                    ]);

                $mailer->send($email);
                $this->addFlash('success', 'Votre réservation est confirmée ! Un email de confirmation a été envoyé.');

            } catch (\Exception $e) {
                $this->addFlash('success', 'Votre réservation est confirmée !');
                $this->addFlash('warning', "L'email de confirmation n'a pas pu être envoyé : " . $e->getMessage());
            }

            return $this->redirectToRoute('app_event_show', ['id' => $id]);
        }

        return $this->render('event/show.html.twig', [
            'event' => $event,
            'form'  => $form,
        ]);
    }
}