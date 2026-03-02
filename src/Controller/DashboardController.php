<?php

namespace App\Controller;

use App\Service\StockService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(StockService $stockService): Response
    {
        $portfolioData = $stockService->getPortfolioSummary();
        $stocks = $portfolioData['stocks'];
        $grandTotal = $portfolioData['grand_total'];

        $errors = array_filter($stocks, fn($stock) => ($stock['error'] ?? null) !== null);

        return $this->render('dashboard.html.twig', [
            'stocks' => $stocks,
            'errors' => $errors,
            'grand_total' => $grandTotal,
            'now' => new \DateTime(),
        ]);
    }
}
