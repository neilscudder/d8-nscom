<?php

namespace Terminus;

use Terminus\Dispatcher;
use Terminus\Dispatcher\CompositeCommand;
use Terminus\Exceptions\TerminusException;
use Terminus\Loggers\Logger;
use Terminus\Outputters\BashFormatter;
use Terminus\Outputters\JSONFormatter;
use Terminus\Outputters\Outputter;
use Terminus\Outputters\PrettyFormatter;
use Terminus\Outputters\StreamWriter;
use Terminus\Utils;

class Runner {
  /**
   * @var array
   */
  private $arguments;
  /**
   * @var array
   */
  private $assoc_args;
  /**
   * @var array
   */
  private $config = [];
  /**
   * @var Configurator
   */
  private $configurator;
  /**
   * @var Logger
   */
  private static $logger;
  /**
   * @var Outputter
   */
  private static $outputter;
  /**
   * @var RootCommand
   */
  private $root_command;

  /**
   * Constructs object. Initializes config, colorization, loger, and outputter
   *
   * @param array $config Extra settings for the config property
   */
  public function __construct(array $config = []) {
    if (!defined('Terminus')) {
      $this->configurator = new Configurator();
      $this->setConfig($config);
      $this->setLogger($this->config);
      $this->setOutputter($this->getConfig('format'), $this->getConfig('output'));
    }
  }

  /**
   * Identifies the command to be run
   *
   * @param array $args The non-hyphenated (--) terms from the CL
   * @return array
   *   0 => [Terminus\Dispatcher\RootCommand]
   *   1 => [array] args
   *   2 => [array] command path
   * @throws TerminusException
   */
  public function findCommandToRun($args) {
    $command = $this->getRootCommand();

    $cmd_path = array();
    while (!empty($args) && $command->canHaveSubcommands()) {
      $cmd_path[] = $args[0];
      $full_name  = implode(' ', $cmd_path);

      $subcommand = $command->findSubcommand($args);

      if (!$subcommand) {
        throw new TerminusException(
          "'{cmd}' is not a registered command. See 'terminus help'.",
          array('cmd' => $full_name),
          1
        );
      }

      $command = $subcommand;
    }

    $command_array = array($command, $args, $cmd_path);
    return $command_array;
  }

  /**
   * Retrieves and returns configuration options
   *
   * @param string $key Hash key of config option to retrieve
   * @return mixed
   * @throws TerminusException
   */
  public function getConfig($key = null) {
    if (is_null($key)) {
      return $this->config;
    }
    if (isset($this->config[$key])) {
      return $this->config[$key];
    }
    throw new TerminusException(
      'There is no configuration option set with the key {key}.',
      compact('key'),
      1
    );
  }

  /**
   * Retrieves the instantiated logger
   *
   * @return Logger $logger
   */
  public static function getLogger() {
    return self::$logger;
  }

  /**
   * Retrieves the instantiated outputter
   *
   * @return OutputterInterface
   */
  public static function getOutputter() {
    return self::$outputter;
  }

  /**
   * Set the logger instance to a class property
   *
   * @param array $config Configuration options to send to the logger
   * @return void
   */
  public static function setLogger($config) {
    self::$logger = new Logger(compact('config'));
  }

  /**
   * Set the outputter instance to a class property
   *
   * @param string $format      Type of formatter to set on outputter
   * @param string $destination Where output will be written to
   * @return void
   */
  public static function setOutputter($format, $destination) {
    // Pick an output formatter
    if ($format == 'json') {
      $formatter = new JSONFormatter();
    } elseif ($format == 'bash') {
      $formatter = new BashFormatter();
    } else {
      $formatter = new PrettyFormatter();
    }

    // Create an output service.
    self::$outputter = new Outputter(
      new StreamWriter($destination),
      $formatter
    );
  }

  /**
   * Runs the Terminus command
   *
   * @return void
   */
  public function run() {
    if (empty($this->arguments)) {
      $this->arguments[] = 'help';
    }

    if (isset($this->config['require'])) {
      foreach ($this->config['require'] as $path) {
        require_once $path;
      }
    }

    try {
      // Show synopsis if it's a composite command.
      $r = $this->findCommandToRun($this->arguments);
      if (is_array($r)) {
        /** @var \Terminus\Dispatcher\RootCommand $command */
        list($command) = $r;

        if ($command->canHaveSubcommands()) {
          self::$logger->info($command->getUsage());
          exit;
        }
      }
    } catch (TerminusException $e) {
      self::$logger->debug($e->getMessage());
    }

    $this->runCommand();
  }

  /**
   * Retrieves the root command from the Dispatcher
   *
   * @return \Terminus\Dispatcher\RootCommand
   */
  public function getRootCommand() {
    if (!isset($this->root_command)) {
      $this->setRootCommand();
    }
    return $this->root_command;
  }

  /**
   * Retrieves and returns the users local configuration directory (~/terminus)
   *
   * @return string
   */
  public function getUserConfigDir() {
    $terminus_config_dir = getenv('TERMINUS_CONFIG_DIR');

    if (!$terminus_config_dir) {
      $home = getenv('HOME');
      if (!$home) {
        // sometime in windows $HOME is not defined
        $home = getenv('HOMEDRIVE') . '/' . getenv('HOMEPATH');
      }
      if ($home) {
        $terminus_config_dir = getenv('HOME') . '/terminus';
      }
    }
    return $terminus_config_dir;
  }

  /**
   * Retrieves and returns a list of plugin's base directories
   *
   * @return array
   */
  private function getUserPlugins() {
    $out = array();
    if ($plugins_dir = $this->getUserPluginsDir()) {
      $plugin_iterator = new \DirectoryIterator($plugins_dir);
      foreach ($plugin_iterator as $dir) {
        if (!$dir->isDot()) {
          $out[] = $dir->getPathname();
        }
      }
    }
    return $out;
  }

  /**
   * Retrieves and returns the local config directory
   *
   * @return string
   */
  private function getUserPluginsDir() {
    if ($config = $this->getUserConfigDir()) {
      $plugins_dir = "$config/plugins";
      if (file_exists($plugins_dir)) {
        return $plugins_dir;
      }
    }
    return false;
  }

  /**
   * Includes every command file in the commands directory
   *
   * @param CompositeCommand $parent The parent command to add the new commands to
   *
   * @return void
   */
  private function loadAllCommands(CompositeCommand $parent) {
    // Create a list of directories where commands might live.
    $directories = array();

    // Add the directory of core commands first.
    $directories[] = TERMINUS_ROOT . '/php/Terminus/Commands';

    // Find the command directories from the third party plugins directory.
    foreach ($this->getUserPlugins() as $dir) {
      $directories[] = "$dir/Commands/";
    }

    // Include all class files in the command directories.
    foreach ($directories as $cmd_dir) {
      if ($cmd_dir && file_exists($cmd_dir)) {
        $iterator = new \DirectoryIterator($cmd_dir);
        foreach ($iterator as $file) {
          if ($file->isFile() && $file->isReadable() && $file->getExtension() == 'php') {
            include_once $file->getPathname();
          }
        }
      }
    }

    // Find the defined command classes and add them to the given base command.
    $classes = get_declared_classes();
    $options = ['runner' => $this,];

    foreach ($classes as $class) {
      $reflection = new \ReflectionClass($class);
      if ($reflection->isSubclassOf('Terminus\Commands\TerminusCommand')) {
        Dispatcher\CommandFactory::create(
          $reflection->getName(),
          $parent,
          $options
        );
      }
    }
  }

  /**
   * Runs a command
   *
   * @return void
   */
  private function runCommand() {
    $args       = $this->arguments;
    $assoc_args = $this->assoc_args;
    try {
      /** @var \Terminus\Dispatcher\RootCommand $command */
      list($command, $final_args, $cmd_path) = $this->findCommandToRun($args);
      $name = implode(' ', $cmd_path);

      $return = $command->invoke($final_args, $assoc_args);
      if (is_string($return)) {
        self::$logger->info($return);
      }
    } catch (\Exception $e) {
      if (method_exists($e, 'getReplacements')) {
        self::$logger->error($e->getMessage(), $e->getReplacements());
      } else {
        self::$logger->error($e->getMessage());
      }
      exit($e->getCode());
    }
  }

  /**
   * Initializes configurator, saves config data to it
   *
   * @param array $arg_config Config options with which to override defaults
   * @return void
   */
  private function setConfig($arg_config = array()) {
    $default_config = ['output' => 'php://stdout'];
    $config         = array_merge($default_config, $arg_config);
    $args           = array('terminus', '--debug');
    if (isset($GLOBALS['argv'])) {
      $args = $GLOBALS['argv'];
    }

    // Runtime config and args
    list($args, $assoc_args, $runtime_config) = $this->configurator->parseArgs(
      array_slice($args, 1)
    );

    $this->arguments  = $args;
    $this->assoc_args = $assoc_args;

    $this->configurator->mergeArray($runtime_config);

    $this->config = array_merge($this->configurator->toArray(), $config);
  }

  /**
   * Set the root command instance to a class property
   *
   * @return void
   */
  private function setRootCommand() {
    $this->root_command = new Dispatcher\RootCommand();
    $this->loadAllCommands($this->root_command);
  }

}
