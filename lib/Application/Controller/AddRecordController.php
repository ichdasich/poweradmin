<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Script that handles request to add new records to existing zone
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DomainRecordCreator;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Symfony\Component\Validator\Constraints as Assert;

class AddRecordController extends BaseController
{
    private LegacyLogger $logger;
    private DnsRecord $dnsRecord;
    private DomainRecordCreator $domainRecordCreator;
    private ReverseRecordCreator $reverseRecordCreator;
    private RecordManagerService $recordManager;

    public function __construct(array $request)
    {
        parent::__construct($request);

        // ConfigurationManager is now handled by the BaseController
        $this->logger = new LegacyLogger($this->db);
        $this->dnsRecord = new DnsRecord($this->db, $this->getConfig());

        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $recordCommentService = new RecordCommentService($recordCommentRepository);
        $commentSyncService = new RecordCommentSyncService($recordCommentService);

        $this->recordManager = new RecordManagerService(
            $this->db,
            $this->dnsRecord,
            $recordCommentService,
            $commentSyncService,
            $this->logger,
            $this->getConfig()
        );

        $this->domainRecordCreator = new DomainRecordCreator(
            $this->getConfig(),
            $this->logger,
            $this->dnsRecord,
        );

        $this->reverseRecordCreator = new ReverseRecordCreator(
            $this->db,
            $this->getConfig(),
            $this->logger,
            $this->dnsRecord
        );
    }

    public function run(): void
    {
        $this->checkId();

        $perm_edit = Permission::getEditPermission($this->db);
        $zone_id = htmlspecialchars($_GET['id']);
        $zone_type = $this->dnsRecord->get_domain_type($zone_id);
        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);

        $this->checkCondition($zone_type == "SLAVE"
            || $perm_edit == "none"
            || ($perm_edit == "own" || $perm_edit == "own_as_client")
            && !$user_is_zone_owner, _("You do not have the permission to add a record to this zone."));

        if ($this->isPost()) {
            $this->validateCsrfToken();

            if (isset($_POST['multi_record_mode']) && isset($_POST['records']) && is_array($_POST['records'])) {
                $this->addMultipleRecords();
            } else {
                $this->addRecord();
            }
        }
        $this->showForm();
    }

    private function addRecord(): void
    {
        // These are required fields
        $constraints = [
            'content' => [
                new Assert\NotBlank()
            ],
            'type' => [
                new Assert\NotBlank()
            ]
        ];

        // Optional fields won't be validated if they're empty due to the filter in BaseController

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
        }

        $name = $_POST['name'] ?? '';
        $content = $_POST['content'];
        $type = $_POST['type'];
        $prio = isset($_POST['prio']) && $_POST['prio'] !== '' ? (int)$_POST['prio'] : 0;
        $ttl = isset($_POST['ttl']) && $_POST['ttl'] !== '' ? (int)$_POST['ttl'] : $this->config->get('dns', 'ttl', 3600);
        $comment = $_POST['comment'] ?? '';
        $zone_id = (int)$_GET['id'];

        if (!$this->createRecord($zone_id, $name, $type, $content, $ttl, $prio, $comment)) {
            $this->setMessage('edit', 'error', _('This record was not valid and could not be added.'));
            $this->redirect('index.php?page=edit&id=' . $zone_id);
            return;
        }

        if (isset($_POST['reverse'])) {
            $reverseRecord = $this->createReverseRecord($name, $type, $content, $zone_id, $ttl, $prio, $comment);
            $message = $reverseRecord ? _('Record successfully added. A matching PTR record was also created.') : _('The record was successfully added.');
            $this->setMessage('edit', 'success', $message);
        } elseif (isset($_POST['create_domain_record'])) {
            $domainRecord = $this->createDomainRecord($name, $type, $content, $zone_id, $comment);
            $message = $domainRecord ? _('Record successfully added. A matching A record was also created.') : _('The record was successfully added.');
            $this->setMessage('edit', 'success', $message);
        } else {
            $this->setMessage('edit', 'success', _('The record was successfully added.'));
        }

        // Redirect back to zone edit page
        $this->redirect('index.php?page=edit&id=' . $zone_id);
    }

    private function showForm(): void
    {
        $zone_id = htmlspecialchars($_GET['id']);
        $zone_name = $this->dnsRecord->get_domain_name_by_id($zone_id);
        $isReverseZone = DnsHelper::isReverseZone($zone_name);

        $ttl = $this->config->get('dns', 'ttl', 3600);
        $isDnsSecEnabled = $this->config->get('dnssec', 'enabled', false);

        if (str_starts_with($zone_name, "xn--")) {
            $idn_zone_name = idn_to_utf8($zone_name, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $this->render('add_record.html', [
            'types' => $isReverseZone ? RecordType::getReverseZoneTypes($isDnsSecEnabled) : RecordType::getDomainZoneTypes($isDnsSecEnabled),
            'name' => $_POST['name'] ?? '',
            'type' => $_POST['type'] ?? '',
            'content' => $_POST['content'] ?? '',
            'ttl' => $_POST['ttl'] ?? $ttl,
            'prio' => $_POST['prio'] ?? 0,
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'is_reverse_zone' => $isReverseZone,
            'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', false),
            'iface_add_domain_record' => $this->config->get('interface', 'add_domain_record', false),
            'iface_record_comments' => $this->config->get('interface', 'show_record_comments', true),
        ]);
    }

    public function checkId(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_GET)) {
            $this->showFirstValidationError($_GET);
        }
    }

    private function createRecord(int $zone_id, $name, $type, $content, $ttl, $prio, $comment): bool
    {
        return $this->recordManager->createRecord(
            $zone_id,
            $name,
            $type,
            $content,
            $ttl,
            $prio,
            $comment,
            $_SESSION['userlogin'],
            $_SERVER['REMOTE_ADDR']
        );
    }

    private function createReverseRecord($name, $type, $content, string $zone_id, $ttl, $prio, string $comment): bool
    {
        $result = $this->reverseRecordCreator->createReverseRecord(
            $name,
            $type,
            $content,
            $zone_id,
            $ttl,
            $prio,
            $comment,
            $_SESSION['userlogin']
        );

        if ($result['success']) {
            return true;
        } else {
            $this->setMessage('add_record', 'error', $result['message']);
            return false;
        }
    }

    private function createDomainRecord(string $name, string $type, string $content, string $zone_id, string $comment): bool
    {
        $result = $this->domainRecordCreator->addDomainRecord(
            $name,
            $type,
            $content,
            $zone_id,
            $comment,
            $_SESSION['userlogin']
        );

        if ($result['success']) {
            return true;
        } else {
            $this->setMessage('add_record', 'error', $result['message']);
            return false;
        }
    }

    private function addMultipleRecords(): void
    {
        $zone_id = (int)$_GET['id'];
        $records = $_POST['records'] ?? [];
        $successCount = 0;
        $failureCount = 0;

        if (empty($records)) {
            $this->setMessage('edit', 'error', _('No records were provided.'));
            $this->redirect('index.php?page=edit&id=' . $zone_id);
            return;
        }

        foreach ($records as $record) {
            // Skip incomplete records
            if (empty($record['content']) || empty($record['type'])) {
                continue;
            }

            $name = $record['name'] ?? '';
            $content = $record['content'];
            $type = $record['type'];
            $prio = isset($record['prio']) && $record['prio'] !== '' ? (int)$record['prio'] : 0;
            $ttl = isset($record['ttl']) && $record['ttl'] !== '' ? (int)$record['ttl'] : $this->config->get('dns', 'ttl', 3600);
            $comment = $record['comment'] ?? '';

            if ($this->createRecord($zone_id, $name, $type, $content, $ttl, $prio, $comment)) {
                $successCount++;

                // Handle reverse or domain record creation for individual records
                if (isset($record['reverse']) && $record['reverse']) {
                    $this->createReverseRecord($name, $type, $content, $zone_id, $ttl, $prio, $comment);
                } elseif (isset($record['create_domain_record']) && $record['create_domain_record']) {
                    $this->createDomainRecord($name, $type, $content, $zone_id, $comment);
                }
            } else {
                $failureCount++;
            }
        }

        if ($successCount > 0) {
            $message = sprintf(_('%d record(s) were successfully added.'), $successCount);
            if ($failureCount > 0) {
                $message .= ' ' . sprintf(_('%d record(s) failed to be added.'), $failureCount);
                $this->setMessage('edit', 'warning', $message);
            } else {
                $this->setMessage('edit', 'success', $message);
            }
        } else {
            $this->setMessage('edit', 'error', _('Failed to add any records.'));
        }

        // Redirect back to zone edit page
        $this->redirect('index.php?page=edit&id=' . $zone_id);
    }
}
