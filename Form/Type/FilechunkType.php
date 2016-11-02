<?php

namespace MakinaCorpus\FilechunkBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Component\Form\CallbackTransformer;

/**
 * Uses the Drupal filechunk module widget, until a brand new one exists.
 *
 * HUGE TODO: FILE IDENTIFIERS COULD BE STOLEN AND REFERENCE OTHER FILES IN THE SYSTEM
 *   => USE SESSION TO STORE UPLOADED FILES INSTEAD
 */
class FilechunkType extends AbstractType
{
    const SESSION_TOKEN = 'filechunk_token';

    private $uploadDirectory;
    private $session;
    private $router;

    /**
     * Default constructor
     *
     * @param string $uploadDirectory
     * @param SessionInterface $session
     * @param RouterInterface $router
     */
    public function __construct($uploadDirectory, SessionInterface $session, RouterInterface $router)
    {
        if (!$uploadDirectory) {
            $uploadDirectory = sys_get_temp_dir() . '/filechunk';
        }

        $this->uploadDirectory = $uploadDirectory;
        $this->session = $session;
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
     * Build or fetch the token, associated to the session
     */
    private function getCurrentToken()
    {
        $token = $this->session->get(self::SESSION_TOKEN);

        if (!$token) {
            // @todo find something better
            $token = base64_encode(mt_rand() . mt_rand() . mt_rand());
            $this->session->set(self::SESSION_TOKEN, $token);
        }

        return $token;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver->setDefined($this->getCustomOptionsNames());

        $resolver->setDefaults([
            'required'      => false,
            'compound'      => true,
            'multiple'      => false,
            'token'         => $this->getCurrentToken(),
            'chunksize'     => 1024 * 512,
            'uri-upload'    => $this->router->generate('filechunk_upload'),
            'uri-remove'    => $this->router->generate('filechunk_remove'),
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
        if (function_exists('drupal_add_library')) {
            drupal_add_library('filechunk', 'widget');
        }

        $attributes = [];
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
        $maxSize = null;
        $mimeTypes = null;

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
            }
        }

        // This actually should not be out of this class, but for readability
        // reasons, I'd prefer it to get out and make this class shorter.
        $transformer = new FilechunkTransformer($this->uploadDirectory . '/' . $options['token'], $options['multiple']);

        // Store at the very least the maxSize and mimeType constraints
        // in session to allow the upload to check those, allowing to warn
        // the user he's doing something forbidden before uploadign the
        // whole file.
        $this->session->set('filechunk_' . $options['token'], [
            'maxSize'   => $maxSize,
            'mimeType'  => $mimeTypes,
        ]);

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

        $builder
            ->get('fid')
            ->addModelTransformer(
                new CallbackTransformer(
                    // @todo for pure symfony forms, remove htmlentities() because twig
                    //   autoescape will be set to on, and JSON causes problems
                    'htmlentities',
                    function ($value) { return $value; }
                )
            )
        ;
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
            // way of doing working around this.
            foreach ($form->getConfig()->getModelTransformers() as $transformer) {
                if ($transformer instanceof FilechunkTransformer) {
                    $value['files'] = $transformer->reverseTransform($value);
                }
            }
        }

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
