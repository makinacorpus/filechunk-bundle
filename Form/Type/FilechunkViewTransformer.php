<?php

namespace MakinaCorpus\FilechunkBundle\Form\Type;

use MakinaCorpus\FilechunkBundle\FileManager;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Norm (validation) to View (form data).
 *
 * Norm is always an array of File instance.
 *
 * View is an array containing two keys:
 *   - 'fid': will be a JSON encoded hashmap whose keys are file names and values
 *     are either null (if original file) or file sha1sum if uploaded, File names
 *     will only be the path basename, without the directory,
 *   - 'files': array of File instance that only will serve the purpose of theming
 *     original input in twig template.
 */
class FilechunkViewTransformer implements DataTransformerInterface
{
    private $directory;
    private $fileManager;

    /**
     * Hoping that, during the widget life time, this instance will be kept
     * alive and will always have the transform() being called first using
     * the user-provided value
     */
    private $originalValues = [];

    /**
     * Default constructor
     */
    public function __construct(FileManager $fileManager, string $directory)
    {
        $this->directory = $directory;
        $this->fileManager = $fileManager;
    }

    /**
     * @codeCoverageIgnore
     */
    private function failIfNotFileInstance($key, $file): void
    {
        if (!$file instanceof File) {
            throw new TransformationFailedException(\sprintf(
                "'%s' value is not a %s instanceof nor a valid URI",
                $key, File::class
            ));
        }
    }

    /**
     * @codeCoverageIgnore
     */
    private function findAbsolutePathFromDefaultValues(string $filename): ?string
    {
        if (isset($this->originalValues[$filename])) {
            return $this->originalValues[$filename]->getRealPath();
        }
        return null;
    }

    /**
     * Convert value to array or return default if empty
     */
    private function toArray($input)
    {
        return null === $input ? [] : (\is_array($input) ? $input : [$input]);
    }

    /**
     * {@inheritdoc}
     */
    public function transform($normalizedValues)
    {
        if (!$normalizedValues = $this->toArray($normalizedValues)) {
            return ['fid' => null, 'files' => []];
        }

        $files = $defaults = [];

        /** @var \Symfony\Component\HttpFoundation\File\File $file */
        foreach ($normalizedValues as $key => $file) {
            $this->failIfNotFileInstance($key, $file); // Well, that, should not happen.
            if ($this->fileManager->exists($file->getPathname())) {
                $files[$file->getFilename()] = $file;
                $defaults[$file->getFilename()] = \sha1_file($file->getRealPath());
            } else {
                // File does not exist, we should probably warn the user about it, but
                // we have no meaningful way of doing it. At least, it won't break.
            }
        }

        $this->originalValues = $files;

        // We keep the File instances, it will be useful for rendering in twig.
        return ['fid' => \json_encode($defaults), 'files' => $files];
    }

    /**
     * {@inheritdoc}
     */
    public function reverseTransform($postData)
    {
        if (empty($postData['fid'])) {
            return null;
        }

        $files = \json_decode($postData['fid'], JSON_OBJECT_AS_ARRAY);

        if (!$files) {
            if (JSON_ERROR_NONE === \json_last_error()) {
                return null;
            }
        }

        $ret = [];

        foreach ($files as $data) {
            $filename = $sha1sum = null;

            // Be liberal in what we accept.
            if (\is_array($data)) {
                if (!$filename = ($data['filename'] ?? $data['name'])) {
                    continue;
                }
                $sha1sum = $data['hash'] ?? null;
            } else {
                $filename = (string)$filename; // This may be a default value.
            }

            $target = $this->findAbsolutePathFromDefaultValues($filename);

            if (!$target) { // File is not a default value.
                if (!$sha1sum) {
                    continue; // No SHA1, invalid file upload attempt.
                }

                $target = \sprintf("%s/%s", $this->directory, $filename);

                if (!\file_exists($target) || \sha1_file($target) !== $sha1sum) {
                    continue;
                }
            }

            $ret[] = new File($target);
        }

        return $ret;
    }
}
