# Javascript file upload widget and form type for Symfony

**This is a very early release**, this package is far from being complete
and provide minimal functionnalities as of today.

This package provides a form widget that upload large files as chunks using
JavaScript client side code using the JavaScript window.FileReader API.

Features:

*   Allows very large file uploads;
*   Allows resuming broken uploads;
*   Should be security-wise quite efficient;
*   Form type uses as input and output Symfony ``File`` instances;
*   Allows to bypass most HTTP restrictions on file uploads (size, timeouts);
*   Do not use the PHP file upload mecanisms.

Known browsers to work:

*   Chrome <= 49
*   Edge <= 13
*   IE <= 11
*   Firefox <= 47
*   And probably a few earlier versions, since it only uses a very small
    subset of the FileReader API.

# Installation

```sh
composer require makinacorpus/filechunk-bundle
```

For it work, the JavaScript file to use may be find in the Drupal module, that
you should copy manually in your local assets:
[https://github.com/makinacorpus/drupal-filechunk/blob/master/filechunk.js](https://github.com/makinacorpus/drupal-filechunk/blob/master/filechunk.js)

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

Just use the ``MakinaCorpus\FilechunkBundle\Form\Type\FilechunkType`` form type
in your own form builders.

Default values **MUST** be ``Symfony\Component\HttpFoundation\File\File``
instances, values returned will also be.

# Important notes

*   if you provide default values via the form data, and remove it via the UI
    on the HTML page, you have no way of fetching the removed file list, you
    must take care of this manually: this will be one of the first feature to
    be implemented in the future;

*   uploaded files are NOT PHP uploaded files, but regular files in your
    temporary folder, you need to move them manually (you cannot use the
    ``move_uploaded_file()`` PHP function;

*   You need recent browsers, a downgrade was available, but has been removed
    to be rewriting from the ground up, so please be patient for this.

That's pretty much it, have fun!
