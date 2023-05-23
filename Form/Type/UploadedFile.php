<?php

namespace MakinaCorpus\FilechunkBundle\Form\Type;

use Symfony\Component\HttpFoundation\File\File;

class UploadedFile extends File
{
    private bool $isDefaultValue = false;

    public function __construct(string $path, bool $isDefaultValue)
    {
        // No need to check, those instances will always come from the
        // widget itself, file existence already has been checked.
        parent::__construct($path, false);

        $this->isDefaultValue = $isDefaultValue;
    }

    /**
     * Is this value the default field value, meaning that the user didn't
     * upload a new file instead.
     */
    public function isDefaultValue(): bool
    {
        return $this->isDefaultValue;
    }
}
