<?php

namespace App\G6K\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Console\Helper\ProgressBar;
use App\G6K\Manager\DatasourcesHelper;

class ImportDataSourceCommand extends Command
{

	/**
	 * @var string
	 */
	private $projectDir;

	public function __construct(string $projectDir) {
		parent::__construct();
		$this->projectDir = $projectDir;
	}

	/**
	 * This function parses the '.env' file and returns an array of database parameters
	 *
	 * @access  private
	 * @return  array|false parameters array or false in case of error
	 *
	 */
	private function getParameters(OutputInterface $output) {
		$parameters = array();
		try {
			$dotenv = new Dotenv();
			$dotenv->load($this->projectDir . DIRECTORY_SEPARATOR . '.env');
			$parameters['database_driver'] = 'pdo_' . $this->getParameterValue('DB_ENGINE');
			$parameters['database_host'] = $this->getParameterValue('DB_HOST');
			$parameters['database_port'] = $this->getParameterValue('DB_PORT');
			$parameters['database_name'] = $this->getParameterValue('DB_NAME');
			$parameters['database_user'] = $this->getParameterValue('DB_USER');
			$parameters['database_password'] = $this->getParameterValue('DB_PASSWORD');
			$parameters['database_path'] = $this->getParameterValue('DB_PATH');
			$parameters['database_version'] = $this->getParameterValue('DB_VERSION');
			$parameters['locale'] = $this->getParameterValue('G6K_LOCALE');
			return $parameters;
		} catch (\Exception $e) {
			$output->writeln(sprintf("Unable to get database parameters: %s", $e->getMessage()));
			return false;
		}
	}

	/**
	 * Returns the value of a given parameter
	 *
	 * @access  private
	 * @return  string The value of the parameter
	 *
	 */
	private function getParameterValue($parameter) {
		$value = getenv($parameter);
		$value = str_replace('%kernel.project_dir%', $this->projectDir, $value);
		$value = str_replace('%PUBLIC_DIR%', getenv('PUBLIC_DIR'), $value);
		return $value;
	}

	/**
	 * Configures the current command.
	 *
	 * @access  protected
	 * @return void
	 */
	protected function configure() {
		$this
			// the name of the command (the part after "bin/console")
			->setName('g6k:import-datasource')

			// the short description shown while running "php bin/console list"
			->setDescription('Imports a datasource from an exported json file.')

			// the full command description shown when running the command with
			// the "--help" option
			->setHelp(
				  "This command allows you to import a data source used by one or more of your simulators.\n"
				. "\n"
				. "You must provide:\n"
				. "- the name of the data source (datasourcename).\n"
				. "- the full path of the directory (datasourcepath) where the files of your data source are located.\n"
				. "\n"
				. "The file names will be composed as follows:\n"
				. "- <datasourcepath>/<datasourcename>.schema.json for the schema\n"
				. "- <datasourcepath>/<datasourcename>.json for the data file\n"
			)
		;
		$this
			// configure an argument
			->addArgument('datasourcename', InputArgument::REQUIRED, 'The name of the datasource.')
			->addArgument('datasourcepath', InputArgument::REQUIRED, 'The directoty.')
		;
	}

	/**
	 * Executes the current command.
	 *
	 * @return int|null null or 0 if everything went fine, or an error code
	 *
	 * @throws LogicException When this abstract method is not implemented
	 *
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$schemafile = $input->getArgument('datasourcepath') . DIRECTORY_SEPARATOR . $input->getArgument('datasourcename') . ".schema.json";
		$datafile = $input->getArgument('datasourcepath') . DIRECTORY_SEPARATOR . $input->getArgument('datasourcename') . ".json";
		$output->writeln([
			'Datasource Importer',
			'===================',
			'',
		]);
		if (! file_exists($schemafile)) {
			$output->writeln(sprintf("The schema file '%s' doesn't exists", $schemafile));
			return 1;
		}
		if (! file_exists($datafile)) {
			$output->writeln(sprintf("The data file '%s' doesn't exists", $datafile));
			return 1;
		}
		if (($parameters = $this->getParameters($output)) === false) {
			return 1;
		}
		$output->writeln("Importing the datasource '".$input->getArgument('datasourcename')."' located in '" . $input->getArgument('datasourcepath') . "'");
		$databasesDir = $this->projectDir . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'databases';
		if ($parameters['database_driver'] == 'pdo_sqlite') {
			$parameters['database_path'] = $databasesDir . DIRECTORY_SEPARATOR . $input->getArgument('datasourcename'). ".db";
		} else {
			$parameters['name'] = $input->getArgument('datasourcename');
		}
		$datasrc = $databasesDir . '/DataSources.xml';
		$helper = new DatasourcesHelper(new \SimpleXMLElement($datasrc, LIBXML_NOWARNING, true));
		$dsid = 0;
		$currentTable = $progressBar = null;
		$dom = $helper->makeDatasourceDom($schemafile, $datafile, $parameters, $databasesDir, $dsid, function($table, $nrows, $rownum) use ($output, &$currentTable, &$progressBar) {
			if ($currentTable != $table) {
				if ($progressBar !== null) {
					$progressBar->finish();
				}
				$output->writeln("\nUpdating table " . $table);
				$currentTable = $table;
				$progressBar = new ProgressBar($output, $nrows);
				$progressBar->start();
			} else {
				$progressBar->advance();
			}
		});
		if ($progressBar !== null) {
			$progressBar->finish();
		}
		$datasources = $dom->saveXML(null, LIBXML_NOEMPTYTAG);
		$dom = new \DOMDocument();
		$dom->preserveWhiteSpace  = false;
		$dom->formatOutput = true;
		$dom->loadXml($datasources);
		$formatted = preg_replace_callback('/^( +)</m', function($a) { 
			return str_repeat("\t", intval(strlen($a[1]) / 2)).'<'; 
		}, $dom->saveXML(null, LIBXML_NOEMPTYTAG));
		file_put_contents($databasesDir."/DataSources.xml", $formatted);
		$output->writeln(sprintf("\nThe data source '%s' is successfully imported", $input->getArgument('datasourcename')));
		return 0;
	}
}
