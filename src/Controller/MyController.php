<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MyController extends AbstractController
{
    // Services can also be injected as action arguments; only this action needs the repository.
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(OrderRepository $orders): Response
    {
        return $this->render('home/index.html.twig', [
            'latestOrders' => $orders->findLatestWithDetails(20),
            'orderCount' => $orders->count(),
            'statusCounts' => $orders->countByStatus(),
            'revenue' => $orders->totalRevenue(),
        ]);
    }
}
