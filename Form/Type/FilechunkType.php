<?php

namespace MakinaCorpus\FilechunkBundle\Form\Type;

use MakinaCorpus\FilechunkBundle\FileManager;
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
use Symfony\Component\Validator\Constraints\All as AllContraint;
use Symfony\Component\Validator\Constraints\Count as CountConstraint;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

class FilechunkType extends AbstractType
{
    const SESSION_TOKEN = 'filechunk_token';

    private $fileManager;
    private $router;
    private $sessionHandler;

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
        $maxSize = $mimeTypes = $maxCount = null;

        if (!empty($constraints)) {
            foreach ($constraints as $constraint) {

                // This algorithm is stupid, and if the same constraint exists
                // more than once, the latter will override the former.
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

                // When field is multiple, constraints can be nested using the
                // "All" constraint, if we want to have working upload validator
                // behavior, we must catch them as well
                if ($constraint instanceof AllContraint) {
                    list($nestedMaxSize, $nestedMimeTypes, $nestedMaxCount) = $this->aggregatesContraints($constraint->constraints, $attributes);
                    if ($nestedMaxSize) {
                        $maxSize = $nestedMaxSize;
                    }
                    if ($nestedMimeTypes) {
                        $mimeTypes = $mimeTypes;
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

        // This actually should not be out of this class, but for readability
        // reasons, I'd prefer it to get out and make this class shorter.
        // @todo implement both the model transforme and the data transformer
        //   if possible both on this class.
        $transformer = new FilechunkTransformer(
            $this->fileManager,
            $this->sessionHandler->getTemporaryFilePath($name),
            $options['multiple']
        );

        // Store at the very least the maxSize and mimeType constraints
        // in session to allow the upload to check those, allowing to warn
        // the user he's doing something forbidden before uploading the
        // whole file.
        $this->sessionHandler->addFieldConfig($name, ['maxSize' => $maxSize, 'mimeType' => $mimeTypes, 'maxCount' => $maxCount]);

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
            // @todo explore using a view transformer instead...
            //   I think this would be the right way to do it. I guess at the
            //   time I wasn't able to find the Symfony documentation about
            //   view transformers against model transformer.
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
        // @todo explore why this is still here, it should not since we
        //    drop drupal support as of 2.x version
        $view->vars['fid_id'] = \sprintf('%s_%s', $view->vars['id'], 'fid');
        $view->vars['fid_name'] = \sprintf('%s[%s]', $view->vars['full_name'], 'fid');

        // If the widget is not multiple, the view is, and we need to convert
        // a single file to an array containing this file for the template.
        if (!empty($value['files']) && !\is_array($value['files'])) {
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
