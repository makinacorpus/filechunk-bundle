# File upload widget and file management for Symfony

This bundle provides various helpers for managing files in Symfony:

 - a chunked file upload endpoint, that is tailored to be used with the
   https://github.com/makinacorpus/filechunk-front widget, but that may
   be used by any other component,

 - a file manager that allows you to register logical schemes (such as
   `upload://`, `temporary://`, ... and convert back and forth absolute
   path names and scheme-based URIs, allowing you to store protocol
   relative URIs in database avoiding absolute path handling nightmare.

The chunked file upload endpoint allows:

 - very large file uploads,
 - resuming broken uploads,
 - bypassing most HTTP restrictions on file uploads (size, timeouts);
 - avoiding the PHP file upload mecanisms,
 - provides a form type uses as input and output Symfony ``File`` instances,
 - should be security-wise quite efficient.

Known browsers to work with the external JavaScript widget:

 - Chrome <= 49
 - Edge <= 13
 - IE <= 11
 - Firefox <= 33
 - And probably others, since it only uses a very small subset of the FileReader API.

# Installation

```sh
composer require makinacorpus/filechunk-bundle
```

Current version does not carry the associated JavaScript widget, you must install
it from: [https://github.com/makinacorpus/filechunk-front](https://github.com/makinacorpus/filechunk-front)

Optionnally, if you are working in a Drupal 7 context, you may just install the following
module: [https://github.com/makinacorpus/drupal-filechunk](https://github.com/makinacorpus/drupal-filechunk)
instead of manually registering the JavaScript widget.

Register the routing.yml file in your ``app/routing.yml`` file:

```yaml
filechunk:
    resource: "@FilechunkBundle/Resources/config/routing.yml"
    prefix: /
```

And the associated form theme in your ``app/config.yml`` file:

```yaml
twig:
    debug:            "%kernel.debug%"
    strict_variables: false
    form_themes:
        # ...
        - "FilechunkBundle:Form:fields.html.twig"
```

And it should probably work.

# Usage

## Basic usage

Just use the ``MakinaCorpus\FilechunkBundle\Form\Type\FilechunkType`` form type
in your own form builders.

Default values **MUST** be ``Symfony\Component\HttpFoundation\File\File``
instances, values returned will also be.

## Validation

You may happily use the ``Symfony\Component\Validator\Constraints\File`` file
constraint to validate you file:

```php
    $this
        ->createFormBuilder()
        ->add('photo', FilechunkType::class, [
            'label' => "Photo",
            'multiple' => false,
            'required' => true,
            'constraints' => [
                new Assert\NotBlank(),
                new Assert\File(['mimeTypes' => ['image/jpg', 'image/jpeg', 'image/png', 'application/pdf']]),
            ],
        ])
```

## Caveat with multiple values

When using the ``multiple`` property set to true, you cannot just apply the
``Assert\File`` validator, if you do, since the widget will return an array
of files the validator will fail. To get around this problem, here is a real life
working example on how to tranform the previous example:


```php
    $this
        ->createFormBuilder()
        ->add('photo', FilechunkType::class, [
            'label' => "Photo",
            'multiple' => false,
            'required' => true,
            'constraints' => [
                new Assert\NotBlank(),
                new All([
                    'constraints' => [
                        new Assert\File(['mimeTypes' => ['image/jpg', 'image/jpeg', 'image/png', 'application/pdf']]),
                    ],
                ]),
            ],
       ])
```

You may find a better explaination of this there [http://blog.arithm.com/2014/11/24/validating-multiple-files-in-symfony-2-5/](http://blog.arithm.com/2014/11/24/validating-multiple-files-in-symfony-2-5/)

## Using validation group when working with multiple values

Same as upper, but you have validation groups too, you need to cascade the groups
in the whole validator chain, this way:

```php
    $this
        ->createFormBuilder()
        ->add('photo', FilechunkType::class, [
            'label' => "Photo",
            'multiple' => false,
            'required' => true,
            'constraints' => [
                new Assert\NotBlank([
                    'groups' => ['some', 'group'],
                ]),
                new All([
                    'groups' => ['some', 'group'],
                    'constraints' => [
                        new Assert\File(
                            'groups' => ['some', 'group'],
                            'mimeTypes' => ['image/jpg', 'image/jpeg', 'image/png', 'application/pdf'],
                        ]),
                    ],
                ]),
            ],
       ])
```

# Important notes

 - if you provide default values via the form data, and remove it via the UI
   on the HTML page, you have no way of fetching the removed file list, you
   must take care of this manually: this will be one of the first feature to
   be implemented in the future;

 - uploaded files are NOT PHP uploaded files, but regular files in your
   temporary folder, you need to move them manually (you cannot use the
   ``move_uploaded_file()`` PHP function;

 - You need recent browsers.

That's pretty much it, have fun!
