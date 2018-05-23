<?php

namespace MakinaCorpus\FilechunkBundle\Form\Type;

use MakinaCorpus\FilechunkBundle\FileSessionHandler;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraints\Count as CountConstraint;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

/**
 * Uses the Drupal filechunk module widget, until a brand new one exists.
 */
class FilechunkType extends AbstractType
{
    const SESSION_TOKEN = 'filechunk_token';

    private $sessionHandler;
    private $router;

    /**
     * Default constructor
     */
    public function __construct(FileSessionHandler $sessionHandler, RouterInterface $router)
    {
        $this->sessionHandler = $sessionHandler;
        $this->router = $router;
    }

    /**
     * Allowed custom option list, that will be propagated to JS
     */
    private function getCustomOptionsNames()
    {
        return ['multiple', 'token', 'default', 'chunksize', 'uri-upload', 'uri-remove', 'tpl-item'];
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver->setDefined($this->getCustomOptionsNames());

        $resolver->setDefaults([
            // Set this if the current element name is not enough to deambiguate
            // the file folder name. It will, in the end, only add entropy to the
            // temporary file name to avoid collisions if other fields with the
            // same name exist for the same session.
            'local_name'      => null,
            'required'        => false,
            'compound'        => true,
            'multiple'        => false,
            'error_bubbling'  => false,
            'token'           => $this->sessionHandler->getCurrentToken(),
            'chunksize'       => 1024 * 512,
            'uri-upload'      => $this->router->generate('filechunk_upload'),
            'uri-remove'      => $this->router->generate('filechunk_remove'),
        ]);

        $resolver->setAllowedTypes('token', ['null', 'string']);
        $resolver->setAllowedTypes('chunksize', ['null', 'int']);
        $resolver->setAllowedTypes('uri-upload', ['null', 'string']);
        $resolver->setAllowedTypes('uri-remove', ['null', 'string']);
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        if (empty($options['local_name'])) {
            $name = $builder->getName();
        } else {
            $name = $options['local_name'];
        }

        // Do not do this this ugly Drupal bridge thingy in cli, it will mostly
        // break your custom Symfony functionnal tests.
        if (php_sapi_name() !== 'cli') {
            if (function_exists('drupal_static') && function_exists('drupal_add_library')) {
                drupal_add_library('filechunk', 'widget');
            }
            if (function_exists('drupal_static') && function_exists('drupal_page_is_cacheable')) {
                drupal_page_is_cacheable(false);
            }
        }

        $attributes = [];
        $attributes['data-field-name'] = $name;
        foreach ($this->getCustomOptionsNames() as $key) {
            if (isset($options[$key])) {
                $value = $options[$key];
                if ('multiple' === $key && $value) {
                    $attributes['multiple'] = 'multiple';
                } else if ('default' === $key) {
                    $value = empty($value) ? null : json_encode($value);
                    $attributes['data-' . $key] = $value;
                } else {
                    $attributes['data-' . $key] = $value;
                }
            }
        }

        // We need to replicate maxSize and mimeTypes constraints if present
        // to be able to validate it the other side of the mirror (during the
        // upload request).
        $maxSize = $mimeTypes = $maxCount = null;
        if ($options['constraints']) {
            foreach ($options['constraints'] as $key => $constraint) {
                if ($constraint instanceof FileConstraint) {
                    if ($constraint->maxSize) {
                        $maxSize = $constraint->maxSize;
                    }
                    if ($constraint->mimeTypes) {
                        $mimeTypes = $constraint->mimeTypes;
                    }
                }
                if ($constraint instanceof CountConstraint) {
                    $maxCount = $constraint->max;
                    // This one will also be checked by the front code to
                    // drive the UI correctly
                    $attributes['data-max-count'] = $maxCount;
                }
            }
        }

        // This actually should not be out of this class, but for readability
        // reasons, I'd prefer it to get out and make this class shorter.
        $transformer = new FilechunkTransformer($this->sessionHandler->getTemporaryFilePath($name), $options['multiple']);

        // Store at the very least the maxSize and mimeType constraints
        // in session to allow the upload to check those, allowing to warn
        // the user he's doing something forbidden before uploading the
        // whole file.
        $this->sessionHandler->addFieldConfig($name, ['maxSize' => $maxSize, 'mimeType' => $mimeTypes, 'maxCount' => $maxCount]);

        $builder
            ->add('file', FileType::class, [
                'multiple'    => $options['multiple'],
                'required'    => $options['required'],
                'attr'        => $attributes,
            ])
            ->add('fid', HiddenType::class, [
                'attr'        => ['rel' => 'fid'],
                'required'    => false,
            ])
            ->add('downgrade', HiddenType::class, [
                'attr'        => ['rel' => 'downgrade'],
                'required'    => false,
            ])
            ->addModelTransformer($transformer)
        ;

        // @todo for pure symfony forms, remove htmlentities() because twig
        //   autoescape will be set to on, and JSON causes problems
        $builder->get('fid')->addModelTransformer(new CallbackTransformer('htmlentities', function ($value) { return $value; }));
    }

    /**
     * {@inheritdoc}
     */
    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        $value = &$view->vars['value'];

        if (!empty($value['fid']) && empty($value['files'])) {
            // We come from an invalidated form submission, and raw values are
            // sent back to the widget, which it may not understand, sadly; and
            // from this point, the data transformer is not being called back
            // and the template cannot rebuild the file list.
            // THIS IS A DIRTY HACK, but I'm afraid there is actually no proper
            // way of working around this.
            foreach ($form->getConfig()->getModelTransformers() as $transformer) {
                if ($transformer instanceof FilechunkTransformer) {
                    $value['files'] = $transformer->reverseTransform($value);
                }
            }
        }

        // And because we are using Twig with no autoescape, and that the
        // Symfony form, let's ensure it has been escaped.
        // $value['fid'] = rawurlencode($value['fid']);
        // @todo FOUQUE I AM NOT HAPPY (which one of them are you then?)
        $view->vars['fid_id'] = sprintf('%s_%s', $view->vars['id'], 'fid');
        $view->vars['fid_name'] = sprintf('%s[%s]', $view->vars['full_name'], 'fid');

        // If the widget is not multiple, the view is, and we need to convert
        // a single file to an array containing this file for the template.
        if (!empty($value['files']) && !is_array($value['files'])) {
            $value['files'] = [$value['files']];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'filechunk';
    }
}
