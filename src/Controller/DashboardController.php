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
        $stocks = $stockService->getPortfolioData();

        // Calculate grand total of the portfolio
        $grandTotal = 0.0;
        foreach ($stocks as $stock) {
            $grandTotal += $stock['total_value'];
        }

        return $this->render('dashboard.html.twig', [
            'stocks' => $stocks,
            'grand_total' => $grandTotal,
            'now' => new \DateTime(),
        ]);
    }
}
