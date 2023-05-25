<?php

namespace MakinaCorpus\FilechunkBundle\Form\Type;

use MakinaCorpus\FilechunkBundle\FieldConfig;
use MakinaCorpus\FilechunkBundle\FileSessionHandler;
use MakinaCorpus\Files\FileManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraints\All as AllContraint;
use Symfony\Component\Validator\Constraints\Count as CountConstraint;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

class FilechunkType extends AbstractType
{
    const SESSION_TOKEN = 'filechunk_token';

    private FileManager $fileManager;
    private FileSessionHandler $sessionHandler;
    private RouterInterface $router;

    /**
     * Default constructor
     */
    public function __construct(FileManager $fileManager, FileSessionHandler $sessionHandler, RouterInterface $router)
    {
        $this->fileManager = $fileManager;
        $this->router = $router;
        $this->sessionHandler = $sessionHandler;
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
            'chunksize' => 1024 * 512, // Upload chunk size
            'compound' => true,
            'error_bubbling' => false,
            'local_name' => null, // Set this to add entropy for upload chunk names and avoid collisions
            'multiple' => false,
            'required' => false,
            'return_as_file' => true, // If set to false, it will return URI as string instead
            'token' => $this->sessionHandler->getCurrentToken(), // Do NOT use this
            'uri-remove' => $this->router->generate('filechunk_remove'),
            'uri-upload' => $this->router->generate('filechunk_upload'),
        ]);

        $resolver->setAllowedTypes('token', ['null', 'string']);
        $resolver->setAllowedTypes('chunksize', ['null', 'int']);
        $resolver->setAllowedTypes('uri-upload', ['null', 'string']);
        $resolver->setAllowedTypes('uri-remove', ['null', 'string']);
    }

    /**
     * Find and aggregate constraints we can use in the upload AJAX callback
     */
    private function aggregatesContraints($constraints, array &$attributes) : array
    {
        $maxSize = $maxCount = null;
        $mimeTypes = [];

        if (!empty($constraints)) {
            foreach ($constraints as $constraint) {

                // This algorithm is stupid, and if the same constraint exists
                // more than once, the latter will override the former.
                if ($constraint instanceof FileConstraint) {
                    if ($constraint->maxSize) {
                        $maxSize = $constraint->maxSize;
                    }
                    if ($constraint->mimeTypes) {
                        $mimeTypes = \array_merge($mimeTypes, $constraint->mimeTypes);
                    }
                }

                if ($constraint instanceof CountConstraint) {
                    $maxCount = $constraint->max;
                    // This one will also be checked by the front code to
                    // drive the UI correctly
                    $attributes['data-max-count'] = $maxCount;
                }

                // When field is multiple, constraints can be nested using the
                // "All" constraint, if we want to have working upload validator
                // behavior, we must catch them as well
                if ($constraint instanceof AllContraint) {
                    list($nestedMaxSize, $nestedMimeTypes, $nestedMaxCount) = $this->aggregatesContraints($constraint->constraints, $attributes);
                    if ($nestedMaxSize) {
                        $maxSize = $nestedMaxSize;
                    }
                    if ($nestedMimeTypes) {
                        $mimeTypes = \array_merge($mimeTypes, $nestedMimeTypes);
                    }
                    if ($nestedMaxCount) {
                        $maxCount = $maxCount;
                    }
                }
            }
        }

        return [$maxSize, $mimeTypes, $maxCount];
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

        $attributes = [];
        $attributes['data-field-name'] = $name;

        // Set frontend side options as attributes on the widget.
        foreach ($this->getCustomOptionsNames() as $key) {
            if (isset($options[$key])) {
                $value = $options[$key];
                if ('multiple' === $key && $value) {
                    $attributes['multiple'] = 'multiple';
                } else if ('default' === $key) {
                    $value = empty($value) ? null : json_encode($value);
                    $attributes['data-'.$key] = $value;
                } else {
                    $attributes['data-'.$key] = $value;
                }
            }
        }

        // We need to replicate maxSize and mimeTypes constraints if present
        // to be able to validate it the other side of the mirror (during the
        // upload request).
        list($maxSize, $mimeTypes, $maxCount) = $this->aggregatesContraints($options['constraints'], $attributes);

        // Store at the very least the maxSize and mimeType constraints
        // in session to allow the upload to check those, allowing to warn
        // the user he's doing something forbidden before uploading the
        // whole file.
        $this->sessionHandler->addFieldConfig(FieldConfig::fromArray(
            $name, ['maxsize' => $maxSize, 'mimetypes' => $mimeTypes, 'maxcount' => $maxCount]
        ));

        $builder
            // This won't hold any values, it will only serve as a placeholder
            // for frontend widget to allow files to be accessed via JavaScript
            // by the browser, it will always yield null values on POST.
            ->add('file', FileType::class, [
                'multiple' => $options['multiple'],
                'required' => $options['required'],
                'attr' => $attributes,
            ])
            ->add('fid', HiddenType::class, [
                'attr' => ['rel' => 'fid'],
                'required' => false,
            ])
            ->addModelTransformer(
                new FilechunkModelTransformer($this->fileManager, $options['multiple'], $options['return_as_file'])
            )
            ->addViewTransformer(
                new FilechunkViewTransformer($this->fileManager, $this->sessionHandler->getTemporaryFilePath($name))
            )
        ;

        // @todo for pure symfony forms, remove htmlentities() because twig
        //   autoescape will be set to on, and JSON causes problems
        $builder->get('fid')->addModelTransformer(new CallbackTransformer(fn (?string $value) => $value ? \htmlentities($value) : null, function ($value) { return $value; }));
    }

    /**
     * {@inheritdoc}
     */
    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        $view->vars['fid_id'] = \sprintf('%s_%s', $view->vars['id'], 'fid');
        $view->vars['fid_name'] = \sprintf('%s[%s]', $view->vars['full_name'], 'fid');
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'filechunk';
    }
}
