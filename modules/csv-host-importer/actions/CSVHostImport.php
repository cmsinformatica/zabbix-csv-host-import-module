<?php
/**
  * Zabbix CSV Import Frontend Module
  *
  * @version 6.0.4
  * @author Wolfgang Alper <wolfgang.alper@intellitrend.de>
  * @copyright IntelliTrend GmbH, https://www.intellitrend.de
  * @license GNU Lesser General Public License v3.0
  *
  * You can redistribute this library and/or modify it under the terms of
  * the GNU LGPL as published by the Free Software Foundation,
  * either version 3 of the License, or any later version.
  * However you must not change author and copyright information.
  */

declare(strict_types = 1);

namespace Modules\Ichi\Actions;

use CControllerResponseData;
use CControllerResponseFatal;
use CController as CAction;
use CRoleHelper;
use CUploadFile;
use API;
use CWebUser;

/**
 * CSV Host Importer module action.
 */
class CSVHostImport extends CAction {

	// maximum length of a single CSV line
	const CSV_MAX_LINE_LEN = 1024;

	// character used to separate CSV fields
	const CSV_SEPARATOR = ';';

	// user-friendly messages for upload error codes
	const UPLOAD_ERRORS = [
		0 => 'There is no error, the file uploaded with success',
		1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
		2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
		3 => 'The uploaded file was only partially uploaded',
		4 => 'No file was uploaded',
		6 => 'Missing a temporary folder',
		7 => 'Failed to write file to disk.',
		8 => 'A PHP extension stopped the file upload.',
	];

	private $csvColumns;
	private $hostlist = [];
	private $step = 0;

	/**
	 * Initialize action. Method called by Zabbix core.
	 *
	 * @return void
	 */
	public function init(): void {
		 // define CSV columns
		$this->csvColumns = [
            // Name            Default    Required
			['NAME',           '',        true],
			['VISIBLE_NAME',   '',        true],
			['HOST_GROUPS',    '',        false],
			['HOST_TAGS',      '',        false],
			['PROXY',          '',        false],
			['TEMPLATES',      '',        false],
			['AGENT_IP',       '',        false],
			['AGENT_DNS',      '',        false],
			['AGENT_PORT',     '10050',   false],
			['SNMP_IP',        '',        false],
			['SNMP_DNS',       '',        false],
			['SNMP_PORT',      '161',     false],
			['SNMP_VERSION',   '',        false],
			['DESCRIPTION',    '',        false],
			['HOST_GROUPS',    '',        false],
			['JMX_IP',         '',        false],
			['JMX_DNS',        '',        false],
			['JMX_PORT',       '12345',   false],
		];

		/**
		 * Disable SID (Sessoin ID) validation. Session ID validation should only be used for actions which involde data
		 * modification, such as update or delete actions. In such case Session ID must be presented in the URL, so that
		 * the URL would expire as soon as the session expired.
		 */
		$this->disableCsrfValidation();
	}

	/**
	 * Check and sanitize user input parameters. Method called by Zabbix core. Execution stops if false is returned.
	 *
	 * @return bool true on success, false on error.
	 */
	protected function checkInput(): bool {
		$fields = [
			'step' => 'in 0,1,2',
			'cancel' => 'string',
		];

		$ret = $this->validateInput($fields);

		return $ret;
	}

	/**
	 * Check if the user has permission to execute this action. Method called by Zabbix core.
	 * Execution stops if false is returned.
	 *
	 * @return bool
	 */
	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_ADMINISTRATION_GENERAL);
	}

	private function csvUpload($path): bool {
		// can't continue here if there was no upload
		if (!isset($_FILES['csv_file'])) {
			error(_('Missing file upload.'));
			return false;
		}

		// check if there was a problem with the upload
		$csv_file = $_FILES['csv_file'];
		if ($csv_file['error'] != UPLOAD_ERR_OK) {
			error(_(self::UPLOAD_ERRORS[$csv_file['error']]));
			return false;
		}

		move_uploaded_file($csv_file['tmp_name'], $path);
		return true;
	}

	private function csvParse($path): bool {
		try {
			$row = 1;
			$this->hostlist = [];

			if (($fp = fopen($path, 'r')) !== FALSE) {
				// get first CSV line, which is the header
				$header = fgetcsv($fp, self::CSV_MAX_LINE_LEN, self::CSV_SEPARATOR);
				if ($header === FALSE) {
					error(_('Empty CSV file.'));
					return false;
				}

				// trim and upper-case all values in the header row
				$header_count = count($header);
				for ($i = 0; $i < $header_count; $i++) {
					$header[$i] = trim(strtoupper($header[$i]));
				}

				// check if all required columns are defined (surplus columns are silently ignored)
				foreach ($this->csvColumns as $csvColumn) {
					if ($csvColumn[2] && array_search($csvColumn[0], $header) === false) {
						error(_s('Missing required column "%1$s" in CSV file.', $csvColumn->name()));
						return false;
					}
				}

				// get all other records till the end of the file
				$linenum = 1; // header was already read, so start at 1
				while (($line = fgetcsv($fp, self::CSV_MAX_LINE_LEN, self::CSV_SEPARATOR)) !== FALSE) {
					$linenum++;
					$column_count = count($line);
					if ($column_count < $header_count) {
						error(_s('Missing column "%1$s" in line %2$d"', $header[$column_count], $linenum));
						continue;
					}

					$host = [];
					foreach ($line as $index => $value) {
						if ($index >= $header_count) {
							// ignore surplus columns
							break;
						}
						$host[$header[$index]] = trim($value);
					}

					// make sure all columns are defined
					foreach ($this->csvColumns as $csvColumn) {
						// required coumns not only must exist but also be non-empty
						if ($csvColumn[2] && trim($host[$csvColumn[0]]) === '') {
							error(_s('Empty required column "%1$s" in CSV file line %2$d.', $csvColumn[0], $linenum));
							return false;
						}

						if (!array_key_exists($csvColumn[0], $host)) {
							$host[$csvColumn[0]] = $csvColumn[1];
						}
					}

					$this->hostlist[] = $host;
				}
				fclose($fp);
			}
		} catch (Exception $e) {
			// catch potential parsing exceptions and display them in the view
			error($e->getMessage());
			return false;
		}

		return true;
	}

	private function importHosts(): bool {
		foreach ($this->hostlist as &$host) {
			$zbxhost = [
				'host' => $host['NAME']
			];

			if ($host['VISIBLE_NAME'] !== '') {
				$zbxhost['name'] = $host['VISIBLE_NAME'];
			}

			if ($host['DESCRIPTION'] !== '') {
				$zbxhost['description'] = $host['DESCRIPTION'];
			}

			if ($host['HOST_GROUPS'] !== '') {
				$hostgroups = explode(',', $host['HOST_GROUPS']);
				$zbxhostgroups = [];

				foreach ($hostgroups as $hostgroup) {
					$hostgroup = trim($hostgroup);
					if ($hostgroup === '') {
						continue;
					}

					$hostgroup = trim($hostgroup);
					$zbxhostgroup = API::HostGroup()->get([
						'output' => ['groupid'],
						'filter' => ['name' => $hostgroup],
						'limit' => 1
					]);

					if ($zbxhostgroup === '') {
						$result = API::HostGroup()->create(['name' => $hostgroup]);
						$zbxhostgroup = [['groupid' => $result['groupids'][0]]];
					}

					$zbxhostgroups[] = $zbxhostgroup[0];
				}

				$zbxhost['groups'] = $zbxhostgroups;
			}

			if ($host['HOST_TAGS'] !== '') {
				$hosttags = explode(',', $host['HOST_TAGS']);
				$zbxhost['tags'] = [];

				foreach ($hosttags as $hosttag) {
					if ($hosttag === '') {
						continue;
					}

					$tagname = '';
					$tagvalue = '';

					if (str_contains($hosttag, ':')) {
						$tmp = explode(':', $hosttag, 2);
						$zbxhost['tags'][] = [
							"tag" => $tmp[0],
							"value" => $tmp[1],
						];
					} else {
						$tagname = $hosttag;
						$zbxhost['tags'][] = [
							"tag" => $hosttag,
						];
					}
				}
			}

			if ($host['PROXY'] !== '') {
				$zbxproxy = API::Proxy()->get([
					'output' => ['proxyid'],
					'filter' => ['host' => $host['PROXY']],
					'limit' => 1
				]);

				if ($zbxproxy) {
					$zbxhost['proxy_hostid'] = $zbxproxy[0]['proxyid'];
				} else {
					error(_s('Proxy "%1$s" on host "%2$s" not found.', $host['PROXY'], $host['NAME']));
				}
			}

			if ($host['TEMPLATES'] !== '') {
				$templates = explode(',', $host['TEMPLATES']);
				$zbxtemplates = [];

				foreach ($templates as $template) {
					$template = trim($template);
					if ($template === '') {
						continue;
					}

					$zbxtemplate = API::Template()->get([
						'output' => ['templateid'],
						'filter' => ['name' => $template],
						'limit' => 1
					]);

					if ($zbxtemplate) {
						$zbxtemplates[] = $zbxtemplate[0];
					} else {
						error(_s('Template "%1$s" on host "%2$s" not found.', $template, $host['NAME']));
					}
				}

				$zbxhost['templates'] = $zbxtemplates;
			}

			$zbxinterfaces = [];

			if ($host['AGENT_IP'] !== '' || $host['AGENT_DNS'] !== '') {
				$zbxinterfaces[] = [
					'type' => 1,
					'dns' => $host['AGENT_DNS'],
					'ip' => $host['AGENT_IP'],
					'main' => 1,
					'useip' => $host['AGENT_IP'] !== '' ? 1 : 0,
					'port' => $host['AGENT_PORT'] !== '' ? intval($host['AGENT_PORT']) : 10050,
				];
			}

			if ($host['SNMP_IP'] !== '' || $host['SNMP_DNS'] !== '') {
				$zbxinterfaces[] = [
					'type' => 2,
					'dns' => $host['SNMP_DNS'],
					'ip' => $host['SNMP_IP'],
					'main' => 1,
					'useip' => $host['SNMP_IP'] !== '' ? 1 : 0,
					'port' => $host['SNMP_PORT'] !== '' ? intval($host['SNMP_PORT']) : 161,
					'details' => [
						'version' => $host['SNMP_VERSION'] !== '' ? intval($host['SNMP_VERSION']) : 1,
						'community' => '{$SNMP_COMMUNITY}'
					]
				];
			}

			if ($host['JMX_IP'] !== '' || $host['JMX_DNS'] !== '') {
				$zbxinterfaces[] = [
					'type' => 4,
					'dns' => $host['JMX_DNS'],
					'ip' => $host['JMX_IP'],
					'main' => 1,
					'useip' => $host['JMX_IP'] !== '' ? 1 : 0,
					'port' => $host['JMX_PORT'] !== '' ? intval($host['JMX_PORT']) : 12345,
				];
			}

			if ($zbxinterfaces) {
				$zbxhost['interfaces'] = $zbxinterfaces;
			}

			$result = API::Host()->create($zbxhost);
			$host['HOSTID'] = $result && $result['hostids'] ? $result['hostids'][0] : -1;
		}

		unset($host);

		return true;
	}

    /**
	 * Prepare the response object for the view. Method called by Zabbix core.
	 *
	 * @return void
	 */
	protected function doAction() {
		$tmpPath = sprintf("%s/ichi.hostlist.%d.csv", sys_get_temp_dir(), CWebUser::$data['userid']);

		if ($this->hasInput('step')) {
			$this->step = intval($this->getInput('step')) & 3;
		} else {
			$this->step = 0;
		}

		// reset step if cancelled by user
		if ($this->hasInput('cancel')) {
			$this->step = 0;
		}

		switch ($this->step) {
			case 0:
				// upload
				if (file_exists($tmpPath)) {
					unlink($tmpPath);
				}
				break;
			case 1:
				// preview
				if (!$this->csvUpload($tmpPath) || !$this->csvParse($tmpPath)) {
					$this->step = 0;
				}
				break;
			case 2:
				// import
				if (!file_exists($tmpPath)) {
					error(_('Missing temporary host file.'));
					break;
				}
				if (!$this->csvParse($tmpPath)) {
					error(_('Unexpected parsing error.'));
					break;
				}
				$this->importHosts();
				unlink($tmpPath);
				break;
		}

		$response = new CControllerResponseData([
			'hostlist' => $this->hostlist,
			'step' => $this->step
		]);
		$response->setTitle(_('CSV Host Importer'));
		$this->setResponse($response);
    }
}
?>