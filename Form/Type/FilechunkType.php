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
        return ['multiple', 'token', 'default', 'chunksize', 'uri-upload', 'uri-remove', 'tpl-item', 'destination', 'maxSize'];
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
            'multiple'      => false,
            'token'         => $this->getCurrentToken(),
            'chunksize'     => 1024 * 512,
            'uri-upload'    => $this->router->generate('filechunk_upload'),
            'uri-remove'    => $this->router->generate('filechunk_remove'),
            'destination'   => null,
            'maxSize'       => 1024 * 1024 * 50, // @todo use system configuration instead
            'mimeTypes'     => null, // null means anything
        ]);

        $resolver->setAllowedTypes('token', ['null', 'string']);
        $resolver->setAllowedTypes('chunksize', ['null', 'int']);
        $resolver->setAllowedTypes('uri-upload', ['null', 'string']);
        $resolver->setAllowedTypes('uri-remove', ['null', 'string']);
        $resolver->setAllowedTypes('destination', ['null', 'string']);
        $resolver->setAllowedTypes('maxSize', ['null', 'int']);
        $resolver->setAllowedTypes('mimeTypes', ['null', 'string', 'array']);
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
                if ('destination' === $key) {
                    continue;
                } else if ('multiple' === $key && $value) {
                    $attributes['multiple'] = 'multiple';
                } else if ('default' === $key) {
                    $value = empty($value) ? null : json_encode($value);
                    $attributes['data-' . $key] = $value;
                } else {
                    $attributes['data-' . $key] = $value;
                }
            }
        }

        // Find file validation constraints and propagate it to the nested
        // file element, ensuring file validation using the Symfony file
        // validation component.
        $fileConstraints = [];
        if ($options['constraints']) {
            foreach ($options['constraints'] as $key => $constraint) {
                if ($constraint instanceof FileConstraint) {
                    unset($options['constraints'][$key]);
                    $fileConstraints[] = $constraint;
                    if ($constraint->maxSize) {
                        $options['maxSize'] = $constraint->maxSize;
                    }
                    if ($constraint->mimeTypes) {
                        $options['mimeTypes'] = $constraint->mimeTypes;
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
            'maxSize'   => $options['maxSize'],
            'mimeType'  => $options['mimeTypes'],
        ]);

        $builder
            ->add('file', FileType::class, [
                'multiple'    => $options['multiple'],
                'required'    => $options['required'],
                'attr'        => $attributes,
                'constraints' => $fileConstraints,
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
    }

    /**
     * {@inheritdoc}
     */
    public function buildView(FormView $view, FormInterface $form, array $options)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'filechunk';
    }
}
