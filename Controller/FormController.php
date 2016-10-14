<?php

namespace MakinaCorpus\FilechunkBundle\Controller;

use MakinaCorpus\FilechunkBundle\Form\Type\FilechunkType;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;

class FormController extends Controller
{
    public function testAction(Request $request)
    {
        $testFiles = [];
        foreach (['hey.jpg', 'ho.png'] as $name) {
            $path = sys_get_temp_dir() . '/' . $name;
            if (!file_exists($path)) {
                file_put_contents($path, 'nan nan nan nan nan batman');
            }
            $file = new File($path);
            $testFiles[] = $file;
        }

        $data = ['files_with_values' => $testFiles];

        $form = $this
            ->createFormBuilder($data)
            ->add('files_multiple', FilechunkType::class, [
                'label'     => "This one is multiple",
                'multiple'  => true,
                'maxSize'   => 1024 * 1024 * 50,
                'mimeTypes' => null,
                'required'  => true,
            ])
            ->add('files_single', FilechunkType::class, [
                'label'     => "This one is single valued",
                'multiple'  => false,
                'maxSize'   => 1024 * 1024 * 50,
                'mimeTypes' => null,
                'required'  => true,
            ])
            ->add('files_with_values', FilechunkType::class, [
                'label'     => "This one has values",
                'multiple'  => true,
                'maxSize'   => 1024 * 1024 * 50,
                'mimeTypes' => null,
                'required'  => true,
            ])
            ->add('submit', SubmitType::class)
            ->getForm()
        ;

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $this->addFlash('info', '<pre>' . print_r($data, true) . '</pre>');

            return $this->redirectToRoute('filechunk_test');
        }

        return $this->render('FilechunkBundle:Form:test.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
