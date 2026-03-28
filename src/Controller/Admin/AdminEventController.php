<?php
namespace App\Controller\Admin;

use App\Entity\Event;
use App\Form\EventType;
use App\Repository\EventRepository;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminEventController extends AbstractController
{
    #[Route('', name: 'app_admin_dashboard')]
    public function dashboard(EventRepository $eventRepo, ReservationRepository $reservationRepo): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'events' => $eventRepo->findAll(),
            'totalReservations' => count($reservationRepo->findAll()),
            'totalEvents' => count($eventRepo->findAll()),
        ]);
    }

    #[Route('/events/new', name: 'app_admin_event_new')]
    public function new(Request $request, EventRepository $eventRepository): Response
    {
        $event = new Event();
        $form = $this->createForm(EventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $eventRepository->save($event);
            $this->addFlash('success', 'Événement créé avec succès.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        return $this->render('admin/event_form.html.twig', [
            'form' => $form,
            'title' => 'Créer un événement',
        ]);
    }

    #[Route('/events/{id}/edit', name: 'app_admin_event_edit')]
    public function edit(int $id, Request $request, EventRepository $eventRepository): Response
    {
        $event = $eventRepository->find($id);
        if (!$event) throw $this->createNotFoundException();

        $form = $this->createForm(EventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $eventRepository->save($event);
            $this->addFlash('success', 'Événement modifié avec succès.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        return $this->render('admin/event_form.html.twig', [
            'form' => $form,
            'title' => 'Modifier l\'événement',
            'event' => $event,
        ]);
    }

    #[Route('/events/{id}/delete', name: 'app_admin_event_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EventRepository $eventRepository): Response
    {
        $event = $eventRepository->find($id);
        if (!$event) throw $this->createNotFoundException();

        if ($this->isCsrfTokenValid('delete'.$id, $request->request->get('_token'))) {
            $eventRepository->delete($event);
            $this->addFlash('success', 'Événement supprimé.');
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/events/{id}/reservations', name: 'app_admin_event_reservations')]
    public function reservations(int $id, EventRepository $eventRepository): Response
    {
        $event = $eventRepository->find($id);
        if (!$event) throw $this->createNotFoundException();

        return $this->render('admin/reservations.html.twig', [
            'event' => $event,
            'reservations' => $event->getReservations(),
        ]);
    }
}