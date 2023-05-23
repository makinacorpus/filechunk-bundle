<?php

namespace MakinaCorpus\FilechunkBundle\Form\Type;

use MakinaCorpus\Files\FileManager;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Model (input/output) to Norm (validation).
 *
 * Model is either URI, absolute paths, or File instance, it doesn't matter
 * if an array is given or not.
 *
 * Norm is always an array of File instance.
 */
class FilechunkModelTransformer implements DataTransformerInterface
{
    private FileManager $fileManager;

    private bool $asFiles = true;
    private bool $isMultiple = false;

    /**
     * Default constructor
     */
    public function __construct(FileManager $fileManager, bool $isMultiple = false, bool $asFiles = true)
    {
        $this->asFiles = $asFiles;
        $this->fileManager = $fileManager;
        $this->isMultiple = $isMultiple;
    }

    /**
     * Model (input) to Norm (validation)
     *
     * @param string|File $file
     */
    private function modelToNorm($file): File
    {
        return ($file instanceof File) ? $file : $this->fileManager->createSymfonyFile((string) $file, false);
    }

    /**
     * Norm (validation) to Model (output)
     *
     * @param string|File $file
     */
    private function normToModel($file)
    {
        if ($this->asFiles) {
            return ($file instanceof File) ? $file : $this->fileManager->createSymfonyFile((string) $file, false);
        }

        return $this->fileManager->getURI(($file instanceof File) ? $file->getRealPath() : (string) $file);
    }

    /**
     * Convert value to array or return default if empty
     */
    private function toArray($input): array
    {
        return null === $input ? [] : (\is_array($input) ? $input : [$input]);
    }

    /**
     * {@inheritdoc}
     */
    public function transform($values)
    {
        if (!$values = $this->toArray($values)) {
            return [];
        }

        // \array_map() keep keys.
        return \array_map([$this, 'modelToNorm'], $values);
    }

    /**
     * {@inheritdoc}
     */
    public function reverseTransform($normalizedValues)
    {
        if (!$normalizedValues = $this->toArray($normalizedValues)) {
            return $this->isMultiple ? [] : null;
        }

        $ret = \array_map([$this, 'normToModel'], $normalizedValues);

        if ($this->isMultiple) {
            return $ret;
        }

        // Do not attempt the count on an empty array, else the reset()
        // function will return false and validation component will break,
        // especially when it's awaiting for a an array of File instances,
        // the All() validator cannot deal with 'false' values.
        if ($ret) {
            if (1 < \count($ret)) {
                return null; // Error case.
            }
            return \reset($ret);
        }
        return null;
    }
}
