<?php

namespace Terminus\Commands;

use Terminus\Commands\CommandWithSSH;

/**
 * @command wp
 */
class WpCommand extends CommandWithSSH {
  /**
   * {@inheritdoc}
   */
  protected $client = 'WP-CLI';

  /**
   * {@inheritdoc}
   */
  protected $command = 'wp';

  /**
   * {@inheritdoc}
   */
  protected $unavailable_commands = [
    'db' => '',
  ];

  /**
   * Invoke `wp` commands on a Pantheon development site
   *
   * <commands>...
   * : The WP-CLI command you intend to run with its arguments, in quotes
   *
   * [--site=<site>]
   * : The name (DNS shortname) of your site on Pantheon
   *
   * [--env=<environment>]
   * : Your Pantheon environment. Default: dev
   *
   */
  public function __invoke($args, $assoc_args) {
    $elements = $this->getElements($args, $assoc_args);
    $result   = $this->sendCommand($elements);
    if ($this->log()->getOptions('logFormat') != 'normal') {
      $this->output()->outputRecordList($result);
    }
  }

}

