# File upload widget and file management for Symfony

This bundle provides a chunked file upload endpoint, that is tailored to be
used with the https://github.com/makinacorpus/filechunk-front widget, but
that may be used by any other component,

The chunked file upload endpoint allows:

 - very large file uploads,
 - resuming broken uploads,
 - bypassing most HTTP restrictions on file uploads (size, timeouts);
 - avoiding the PHP file upload mecanisms,
 - easy to use with a custom form type that can input and output either ``File``
   instances or string URIs.

Known browsers to work with the external JavaScript widget:

 - Chrome <= 49
 - Edge <= 13
 - IE <= 11
 - Firefox <= 33
 - And probably others, since it only uses a very small subset of the FileReader API.

# Setup

## Installation

```sh
composer require makinacorpus/filechunk-bundle
```

Current version does not carry the associated JavaScript widget, you must install
it from: [https://github.com/makinacorpus/filechunk-front](https://github.com/makinacorpus/filechunk-front)

Optionnally, if you are working in a Drupal 7 context, you may just install the following
module: [https://github.com/makinacorpus/drupal-filechunk](https://github.com/makinacorpus/drupal-filechunk)
instead of manually registering the JavaScript widget.

## Basic configuration

Everything should be auto-configured if you follow the rest of this section.

## Custom schemes configuration

Each custom scheme is tied to a custom folder, allowing you to store protocol
relative URI in your database instead of absolute path, making the application
portable and migrable easily.

Per default, the bundle offers three schemes:

 - `private://` for files that should not be accessible via the HTTPd
   which will default to `%kernel.project_dir/var/private/%`,
 - `public://` for files that will be freely visible via the HTTPd, which
   will default to `%kernel.project_dir/public/files/`,
 - `temporary://` for temporary files, which will default to PHP configured
   temporary folder,
 - `upload://` for chunked file upload, which defaults to `temporary://filechunk/`
 - `webroot://` for files that are in the public directory,
   will default to `%kernel.project_dir/public`,

Only the temporary one cannot be configured, all others can be set via
the following `.env` file variables:

```
FILE_PRIVATE_DIR="%kernel.project_dir%/var/private"
FILE_PUBLIC_DIR="%kernel.project_dir%/public/files"
FILE_UPLOAD_DIR="%kernel.project_dir%/var/tmp/upload"
FILE_WEBROOT_DIR="%kernel.project_dir%/public"
```

## Chunked file upload widget configuration

Register the routing.yml file in your ``config/routes.yaml`` file:

```yaml
filechunk:
    resource: "@FilechunkBundle/Resources/config/routing.yml"
    prefix: /
```

And the associated form theme in your ``config/packages/twig.yaml`` file:

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

## File manager API

Documentation will come soon.

## File widget

### Basic usage

Just use the ``MakinaCorpus\FilechunkBundle\Form\Type\FilechunkType`` form type
in your own form builders.

Default values **MUST** be ``Symfony\Component\HttpFoundation\File\File``
instances, values returned will also be.

### Validation

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

### Caveat with multiple values

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

### Using validation group when working with multiple values

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

### Important notes

 - if you provide default values via the form data, and remove it via the UI
   on the HTML page, you have no way of fetching the removed file list, you
   must take care of this manually: this will be one of the first feature to
   be implemented in the future;

 - uploaded files are NOT PHP uploaded files, but regular files in your
   temporary folder, you need to move them manually (you cannot use the
   ``move_uploaded_file()`` PHP function;

 - You need recent browsers.

That's pretty much it, have fun!
