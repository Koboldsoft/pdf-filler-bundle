<?php

namespace Koboldsoft\PdfFillerBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ButtonController extends AbstractController
{
    private $termineRepo;
    private $auftragRepo;
    private $chatOpenAiService;
    
    
    public function __construct()
    {
        $this->termineRepo = $termineRepo;
        
        $this->auftragRepo = $auftragRepo;
        
        $this->chatOpenAiService = $chatOpenAiService;
    }
    
    /**
     * @Route("/button/press", name="button_press", methods={"GET", "POST"})
     */
    public function press(Request $request): Response
    {
       
    }
    
}

