<?php

namespace Charcoal\Script;

use Exception;
use InvalidArgumentException;

// From PSR-3
use Psr\Log\NullLogger;

// From PSR-7
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// From 'charcoal-app'
use Charcoal\App\Script\AbstractScript;

/**
 * Renames the current module's name
 *
 * The command-line action will alter the module's
 * file names and the contents of files to match
 * the provided name.
 */
class RenameScript extends AbstractScript
{
    use KeyNormalizerTrait;

    /**
     * @var string $sourceName The original string to search and replace.
     */
    protected $sourceName;

    /**
     * @var string $sourceName The default string to search and replace.
     */
    protected $defaultSourceName = 'boilerplate';

    /**
     * @var string $targetName The user-provided name of the project.
     */
    protected $targetName;

    /**
     * @var string $path The path of the folder.
     */
    protected $path;

    /**
     * @var string $excludeFromGlob Namespaces to exclude from replacement.
     */
    protected $excludeFromGlob = '!(\/city|vendor|node_modules|bower_components|mustache_cache'.
    '|www\/assets\/admin|www\/uploads|\.log)($|/)!i';

    // ==========================================================================
    // DEFAULTS
    // ==========================================================================

    /**
     * Retrieve the available default arguments of this action.
     *
     * @link http://climate.thephpleague.com/arguments/ For descriptions of the options for CLImate.
     *
     * @return array
     */
    public function defaultArguments()
    {
        $arguments = [
            'sourceName' => [
                'prefix'       => 's',
                'longPrefix'   => 'source',
                'description'  => sprintf(
                    'What is the project <red>source</red> namespace? [default: <green>%s</green>]',
                    $this->defaultSourceName
                ),
                'defaultValue' => $this->defaultSourceName,
            ],
            'targetName' => [
                'prefix'      => 't',
                'longPrefix'  => 'target',
                'description' => 'What is the project <red>target</red> namespace?',
            ],
            'path'       => [
                'prefix'       => 'p',
                'longPrefix'   => 'path',
                'description'  => 'What is the <red>path</red> to where you want to rename?',
                'defaultValue' => '../*',
            ],
        ];

        $arguments = array_merge(parent::defaultArguments(), $arguments);

        return $arguments;
    }

    // ==========================================================================
    // INIT
    // ==========================================================================

    /**
     * @param array|\ArrayAccess $data The dependencies (app and logger).
     */
    public function __construct($data = null)
    {
        if (!isset($data['logger'])) {
            $data['logger'] = new NullLogger();
        }

        parent::__construct($data);

        $arguments = $this->defaultArguments();
        $this->setArguments($arguments);
    }

    /**
     * Create a new rename script and runs it while passing arguments.
     * @param  array $data The data passed.
     * @return void
     */
    public static function start(array $data = [])
    {
        $script = new RenameScript();

        // Parse data
        foreach ($data as $key => $value) {
            $setter = $script->camel('set-'.$key);
            if (is_callable($script, $setter)) {
                $script->{$setter}($value);
            } else {
                $script->climate()->to('error')->red(sprintf(
                    'the setter "%s" was passed to the start function but doesn\'t exist!',
                    $setter
                ));
            }
        }
        $script->rename();
    }

    /**
     * @see \League\CLImate\CLImate Used by `CliActionTrait`
     *
     * @param  RequestInterface  $request  PSR-7 request.
     * @param  ResponseInterface $response PSR-7 response.
     * @return void
     */
    public function run(RequestInterface $request, ResponseInterface $response)
    {
        // Never Used
        unset($request, $response);

        $climate = $this->climate();

        // Outputs usage
        if ($climate->arguments->defined('help')) {
            $climate->usage();

            return;
        }

        // Necessary arguments
        $this->setPath($this->argOrInput('path'));
        $this->setSourceName($this->argOrInput('sourceName'));
        $this->setTargetName($this->argOrInput('targetName'));

        $climate->underline()->green()->out('Charcoal batch rename script');

        $this->rename();
    }

    // ==========================================================================
    // FUNCTIONS
    // ==========================================================================

    /**
     * Interactively setup a Charcoal module.
     *
     * The action will ask the user a series of questions,
     * and then update the current module for them.
     *
     * It attempts to rename all occurrences of the "source name"
     * with the provided Project name_.
     * @return void
     */
    private function rename()
    {
        $climate = $this->climate();

        // Replace content and rename files.
        $this->replaceFileContent();
        $this->renameFiles();

        $climate->green()->br()->out('Success!');
    }

    /**
     * Replace "source name" in the contents of files.
     *
     * Renames all occurrences of "source name" with the provided Project name_
     * in the contents of all module files.
     *
     * @return void
     */
    protected function replaceFileContent()
    {
        $climate          = $this->climate();
        $targetName       = $this->targetName();
        $snakeTargetName  = self::snake($targetName);
        $studlyTargetName = self::studly($targetName);
        $sourceName       = $this->sourceName();
        $snakeSourceName  = self::snake($sourceName);
        $studlySourceName = self::studly($sourceName);

        $climate->br()->backgroundGreen()->out('Replacing all files content');
        $climate->green()->out(sprintf(
            '<green>Changing </green><white>%s</white> to <white>%s</white>',
            $snakeSourceName,
            $snakeTargetName
        ));
        $climate->green()->out(sprintf(
            '<green>Studly target name: </green><white>%s</white> to <white>%s</white>',
            $studlySourceName,
            $studlyTargetName
        ));
        $climate->br();

        $files = array_merge(
            $this->globRecursive($this->path()),
            glob('*.*')
        );

        // Output array
        $data = [
            [
                '<white>File name</white>',
                $snakeSourceName,
                $studlySourceName,
                'total'
            ]
        ];

        foreach ($files as $filename) {
            if (is_dir($filename)) {
                continue;
            }
            $file            = file_get_contents($filename);
            $numReplacement1 = 0;
            $numReplacement2 = 0;
            $content         = preg_replace(
                '#'.$snakeSourceName.'#',
                $snakeTargetName,
                $file,
                -1,
                $numReplacement1
            );
            $content         = preg_replace(
                '#'.$studlySourceName.'#',
                $studlyTargetName,
                $content,
                -1,
                $numReplacement2
            );
            $numReplacements = ($numReplacement1 + $numReplacement2);
            if ($numReplacements > 0) {
                // Save file content
                file_put_contents($filename, $content);

                $data[] = [
                    $filename,
                    '<white>'.$numReplacement1.'</white>',
                    '<white>'.$numReplacement2.'</white>',
                    '<white>'.$numReplacements.'</white>',
                ];
            }
        }

        $climate->green()->table($data);
    }

    /**
     * Replace "source name" in the names of files.
     *
     * Renames all occurrences of "source name" with the
     * provided _project name_ in all module file names.
     *
     * @return void
     */
    protected function renameFiles()
    {
        $climate          = $this->climate();
        $sourceName       = $this->sourceName();
        $snakeSourceName  = self::snake($sourceName);
        $studlySourceName = self::studly($sourceName);
        $targetName       = $this->targetName();
        $snakeTargetName  = self::snake($targetName);
        $studlyTargetName = self::studly($targetName);

        $climate->br()->backgroundGreen()->out('Renaming files and directories');

        // Output array
        $data = [
            [
                'File name',
                'Rename',
            ],
        ];

        // Replacing SnakeCase
        $sourceFiles = $this->globRecursive($this->path().$snakeSourceName.'*');
        $sourceFiles = array_reverse($sourceFiles);

        foreach ($sourceFiles as $filename) {
            if (!file_exists($filename) && !is_dir($filename)) {
                continue;
            }
            $name = preg_replace('#'.$snakeSourceName.'#', $snakeTargetName, basename($filename));
            $name = dirname($filename).'/'.$name;

            if ($name != $filename) {
                $data[] = [
                    $filename,
                    '<white>'.$name.'</white>',
                ];
                rename($filename, $name);
            }
        }

        $sourceFiles = $this->globRecursive($this->path().$sourceName.'*');
        $sourceFiles = array_reverse($sourceFiles);

        foreach ($sourceFiles as $filename) {
            $name = preg_replace('/'.$sourceName.'/', $targetName, basename($filename));
            $name = dirname($filename).'/'.$name;

            if ($name != $filename) {
                rename($filename, $name);
                $data[] = [
                    $filename,
                    '<white>'.$name.'</white>',
                ];
            }
        }

        $sourceFiles = $this->globRecursive($this->path().$studlySourceName.'*');
        $sourceFiles = array_reverse($sourceFiles);

        foreach ($sourceFiles as $filename) {
            $name = preg_replace('/'.$studlySourceName.'/', $studlyTargetName, basename($filename));
            $name = dirname($filename).'/'.$name;

            if ($name != $filename) {
                rename($filename, $name);
                $data[] = [
                    $filename,
                    '<white>'.$name.'</white>',
                ];
            }
        }

        $climate->green()->table($data);
    }

    // ==========================================================================
    // SETTERS
    // ==========================================================================

    /**
     * Set the current project name.
     *
     * @param  string $targetName The target name of the project.
     * @throws InvalidArgumentException If the project name is invalid.
     * @return RenameScript             Chainable
     */
    public function setTargetName($targetName)
    {
        if (!is_string($targetName)) {
            throw new InvalidArgumentException(
                'Invalid project name. Must be a string.'
            );
        }

        if (!$targetName) {
            throw new InvalidArgumentException(
                'Invalid project name. Must contain at least one character.'
            );
        }

        if (!preg_match('/^[-a-z_ ]+$/i', $targetName)) {
            throw new InvalidArgumentException(
                'Invalid project name. Only characters A-Z, dashes, underscores and spaces are allowed.'
            );
        }

        // Convert to camel case
        $targetName = self::camel($targetName);

        $this->targetName = $targetName;

        return $this;
    }

    /**
     * Set the current source name.
     *
     * @param  string $sourceName The name of the project.
     * @throws InvalidArgumentException If the project name is invalid.
     * @return RenameScript             Chainable
     */
    public function setSourceName($sourceName)
    {
        if (!is_string($sourceName)) {
            throw new InvalidArgumentException(
                'Invalid source namespace name. Must be a string.'
            );
        }

        if (!$sourceName) {
            throw new InvalidArgumentException(
                'Invalid source namespace name. Must contain at least one character.'
            );
        }

        if (!preg_match('/^[-a-z_ ]+$/i', $sourceName)) {
            throw new InvalidArgumentException(
                'Invalid project name. Only characters A-Z, dashes, underscores and spaces are allowed.'
            );
        }

        // Convert to camel case
        $sourceName = self::camel($sourceName);

        $this->sourceName = $sourceName;

        return $this;
    }

    /**
     * @param string $path The path.
     * @return void
     */
    public function setPath($path)
    {
        $this->path = $path;
    }

    // ==========================================================================
    // GETTERS
    // ==========================================================================

    /**
     * Retrieve the current project target name.
     *
     * @return string
     */
    public function targetName()
    {
        return $this->targetName;
    }

    /**
     * Retrieve the current source name.
     *
     * @return string
     */
    public function sourceName()
    {
        return $this->sourceName;
    }

    /**
     * Retrieve the response to the action.
     *
     * @return array
     */
    public function response()
    {
        return [
            'success' => $this->success(),
        ];
    }

    /**
     * @return string
     */
    public function path()
    {
        return $this->path;
    }


    // ==========================================================================
    // UTILS
    // ==========================================================================

    /**
     * Recursively find pathnames matching a pattern
     *
     *                         an empty array if no file matched or FALSE on error.
     * @see glob() for a description of the function and its parameters.
     *
     * @param  string  $pattern The search pattern.
     * @param  integer $flags   The glob flags.
     * @return array   Returns an array containing the matched files/directories,
     */
    private function globRecursive($pattern, $flags = 0)
    {
        $files = glob($pattern, $flags);

        foreach (glob(dirname($pattern).'/*', (GLOB_ONLYDIR | GLOB_NOSORT)) as $dir) {
            if (!preg_match($this->excludeFromGlob, $dir)) {
                $files = array_merge($files, $this->globRecursive($dir.'/'.basename($pattern), $flags));
            }
        }

        return $files;
    }
}
