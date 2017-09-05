<?php

namespace MakinaCorpus\FilechunkBundle\Form\Type;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\HttpFoundation\File\File;

class FilechunkTransformer implements DataTransformerInterface
{
    private $uploadDirectory;
    private $isMultiple = false;

    /**
     * Hoping that, during the widget life time, this instance will be kept
     * alive and will always have the transform() being called first using
     * the user-provided value
     */
    private $originalValues = [];

    /**
     * Default constructor
     *
     * @param string $uploadDirectory
     * @param string $isMultiple
     */
    public function __construct($uploadDirectory, $isMultiple = false)
    {
        $this->uploadDirectory = $uploadDirectory;
        $this->isMultiple = $isMultiple;
    }

    /**
     * {@inheritdoc}
     */
    public function transform($value)
    {
        if ($value === null || empty($value)) {
            return ['fid' => null, 'files' => []];
        }
        if (!is_array($value)) {
            $value = [$value];
        }

        $files = [];
        $hashes = [];
        foreach ($value as $key => $file) {
            if (!$file instanceof File) {
                throw new TransformationFailedException(sprintf("'%s' value is not a file", $key));
            }
            $files[$file->getFilename()] = $file;
            $hashes[$file->getFilename()] = md5_file($file->getRealPath());
        }

        $this->originalValues = $files;

        // We must return the correct output the widget needs, by the way the
        // widget is always multiple (even if the user asked for it not to be
        // be) which means we don't have any good reason to check for
        // multipleness here.
        // We keep the 'files' data for themeing (see twig template).
        return ['fid' => json_encode($hashes), 'files' => $files];
    }

    /**
     * {@inheritdoc}
     */
    public function reverseTransform($submitted)
    {
        $ret = [];

        // This method should not throw any exception, else the user will get
        // it in his face in certain cases, so we are just going to remove the
        // wrong files from the widget.

// Handle delete a better way
//         if (!empty($submitted['drop'])) {
//             return null; // And yes, do not keep this frakking file.
//         }

        if (!empty($submitted['downgrade'])) {
            // We are in downgrade mode, files are those from the file input.
            $ret = $submitted['file'];

        } else if (!empty($submitted['fid'])) {
            $fileNames = json_decode($submitted['fid'], JSON_OBJECT_AS_ARRAY);

            foreach ($fileNames as $name => $hash) {

                // At this point, we must ensure that the file was not modified,
                // because if the original was given, it's not stored within the
                // upload directory, and therefore we do not need to check if
                // the file exists or not. In that specific case, we are only
                // going to check if the md5 hash matches.
                if (isset($this->originalValues[$name])) {
                    $target = $this->originalValues[$name]->getRealPath();
                } else {
                    // Normal operation is to check for upload files to be there
                    // and ready to work on.
                    $target = $this->uploadDirectory . '/' . $name;
                    if (!file_exists($target)) {
                        continue;
                    }
                    if (md5_file($target) !== $hash) {
                        continue;
                    }
                }

                $ret[] = new File($target);
            }
        }

        if (!$this->isMultiple) {
            // Do not attempt the count on an empty array, else the reset()
            // function will return false and validation component will break,
            // especially when it's awaiting for a an array of File instances,
            // the All() validator cannot deal with 'false' values
            if ($ret) {
                if (1 < count($ret)) {
                    $ret = []; // Error case.
                } else {
                    $ret = reset($ret);
                }
            } else {
                $ret = null;
            }
        }

        return $ret;
    }
}
