<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Agreement;
use CoyshCRM\Models\Client;
use PDO;

class AgreementController
{
    private Agreement $model;
    private Client $clientModel;

    public function __construct(private PDO $db)
    {
        $this->model       = new Agreement($db);
        $this->clientModel = new Client($db);
    }

    public function index(): void
    {
        $status = in_array($_GET['status'] ?? '', Agreement::STATUSES) ? $_GET['status'] : null;
        $agreements  = $this->model->findAllWithClient($status);
        $breadcrumbs = [['Agreements', null]];
        render('agreements.index', compact('agreements', 'status', 'breadcrumbs'), 'Agreements');
    }

    public function create(int $clientId): void
    {
        $client = $this->clientModel->findById($clientId);
        if (!$client) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $agreement   = ['client_id' => $clientId, 'status' => 'active', 'agreement_type' => 'support'];
        $errors      = [];
        $recurring   = $this->recurringOptions($clientId);
        $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Add Agreement', null]];
        render('agreements.form', compact('client', 'agreement', 'errors', 'recurring', 'breadcrumbs'), 'Add Agreement');
    }

    public function store(int $clientId): void
    {
        if (!csrfCheck()) { flash('error', 'Invalid form token — please try again.'); redirect("/clients/$clientId"); }
        $client = $this->clientModel->findById($clientId);
        if (!$client) { redirect('/clients'); return; }

        $data   = $this->sanitise($_POST, $clientId);
        $errors = $this->validate($data);

        if ($errors) {
            $agreement   = $data;
            $recurring   = $this->recurringOptions($clientId);
            $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Add Agreement', null]];
            render('agreements.form', compact('client', 'agreement', 'errors', 'recurring', 'breadcrumbs'), 'Add Agreement');
            return;
        }

        $this->model->insert($data);
        flash('success', "Agreement '{$data['title']}' added.");
        redirect("/clients/$clientId");
    }

    public function edit(int $clientId, int $id): void
    {
        $client    = $this->clientModel->findById($clientId);
        $agreement = $this->model->findById($id);
        if (!$client || !$agreement || (int)$agreement['client_id'] !== $clientId) {
            http_response_code(404); render('errors.404', [], '404 Not Found'); return;
        }

        $errors      = [];
        $recurring   = $this->recurringOptions($clientId);
        $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Edit Agreement', null]];
        render('agreements.form', compact('client', 'agreement', 'errors', 'recurring', 'breadcrumbs'), 'Edit Agreement');
    }

    public function update(int $clientId, int $id): void
    {
        if (!csrfCheck()) { flash('error', 'Invalid form token — please try again.'); redirect("/clients/$clientId"); }
        $client    = $this->clientModel->findById($clientId);
        $agreement = $this->model->findById($id);
        if (!$client || !$agreement || (int)$agreement['client_id'] !== $clientId) {
            http_response_code(404); render('errors.404', [], '404 Not Found'); return;
        }

        $data   = $this->sanitise($_POST, $clientId);
        $errors = $this->validate($data);

        if ($errors) {
            $agreement   = array_merge($agreement, $data);
            $recurring   = $this->recurringOptions($clientId);
            $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Edit Agreement', null]];
            render('agreements.form', compact('client', 'agreement', 'errors', 'recurring', 'breadcrumbs'), 'Edit Agreement');
            return;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->model->update($id, $data);
        flash('success', "Agreement '{$data['title']}' updated.");
        redirect("/clients/$clientId");
    }

    public function destroy(int $clientId, int $id): void
    {
        if (!csrfCheck()) { flash('error', 'Invalid form token — please try again.'); redirect("/clients/$clientId"); }
        $agreement = $this->model->findById($id);
        if ($agreement && (int)$agreement['client_id'] === $clientId) {
            // Unlink attachments rather than deleting the files
            try {
                $this->db->prepare("UPDATE client_attachments SET agreement_id = NULL WHERE agreement_id = ?")->execute([$id]);
            } catch (\Throwable) {}
            $this->model->delete($id);
            flash('success', "Agreement '{$agreement['title']}' deleted.");
        }
        redirect("/clients/$clientId");
    }

    public function storeWork(int $clientId, int $agreementId): void
    {
        if (!csrfCheck()) { flash('error', 'Invalid form token — please try again.'); redirect("/clients/$clientId"); }
        $agreement = $this->model->findById($agreementId);
        if (!$agreement || (int)$agreement['client_id'] !== $clientId) { redirect("/clients/$clientId"); return; }

        $workDate    = $_POST['work_date'] ?: date('Y-m-d');
        $hours       = (float)($_POST['hours'] ?? 0);
        $description = trim($_POST['description'] ?? '');

        if ($hours <= 0) {
            flash('error', 'Hours must be greater than zero.');
        } else {
            $this->model->addWork($agreementId, $workDate, $hours, $description);
            $updated = $this->model->withUsage($this->model->findById($agreementId));
            $msg = sprintf('%.2g hour(s) logged.', $hours);
            if ($updated['hours_remaining'] !== null) {
                $msg .= sprintf(' %.4g of %.4g hours remaining this period.', $updated['hours_remaining'], (float)$updated['included_hours']);
            }
            flash('success', $msg);
        }
        redirect("/clients/$clientId");
    }

    public function deleteWork(int $clientId, int $agreementId, int $logId): void
    {
        if (!csrfCheck()) { flash('error', 'Invalid form token — please try again.'); redirect("/clients/$clientId"); }
        $agreement = $this->model->findById($agreementId);
        if ($agreement && (int)$agreement['client_id'] === $clientId) {
            $this->model->deleteWork($agreementId, $logId);
            flash('success', 'Work log entry removed.');
        }
        redirect("/clients/$clientId");
    }

    private function recurringOptions(int $clientId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT id, reference, frequency, net_value, recurring_status FROM freeagent_recurring_invoices WHERE client_id = ? ORDER BY recurring_status, reference");
            $stmt->execute([$clientId]);
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    private function sanitise(array $post, int $clientId): array
    {
        return [
            'client_id'          => $clientId,
            'title'              => trim($post['title'] ?? ''),
            'agreement_type'     => array_key_exists($post['agreement_type'] ?? '', Agreement::TYPES) ? $post['agreement_type'] : 'support',
            'status'             => in_array($post['status'] ?? '', Agreement::STATUSES) ? $post['status'] : 'active',
            'covers_hosting'     => isset($post['covers_hosting']) ? 1 : 0,
            'covers_support'     => isset($post['covers_support']) ? 1 : 0,
            'covers_maintenance' => isset($post['covers_maintenance']) ? 1 : 0,
            'included_hours'     => ($post['included_hours'] ?? '') !== '' ? (float)$post['included_hours'] : null,
            'hours_period'       => in_array($post['hours_period'] ?? '', Agreement::HOURS_PERIODS) ? $post['hours_period'] : null,
            'fee_amount'         => ($post['fee_amount'] ?? '') !== '' ? (float)$post['fee_amount'] : null,
            'fee_currency'       => in_array($post['fee_currency'] ?? '', ['GBP', 'USD', 'EUR']) ? $post['fee_currency'] : 'GBP',
            'fee_billing_cycle'  => in_array($post['fee_billing_cycle'] ?? '', Agreement::BILLING_CYCLES) ? $post['fee_billing_cycle'] : null,
            'freeagent_recurring_invoice_id' => ($post['freeagent_recurring_invoice_id'] ?? '') !== '' ? (int)$post['freeagent_recurring_invoice_id'] : null,
            'start_date'         => $post['start_date'] ?: null,
            'renewal_date'       => $post['renewal_date'] ?: null,
            'response_terms'     => trim($post['response_terms'] ?? ''),
            'notes'              => trim($post['notes'] ?? ''),
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!$data['title']) $errors['title'] = 'Agreement title is required.';
        if ($data['included_hours'] !== null && $data['included_hours'] <= 0) {
            $errors['included_hours'] = 'Included hours must be greater than zero (or left blank).';
        }
        if ($data['included_hours'] !== null && !$data['hours_period']) {
            $errors['hours_period'] = 'Choose a period for the hours allowance.';
        }
        return $errors;
    }
}
