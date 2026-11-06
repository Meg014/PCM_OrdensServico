<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\NonconformityConsultationService;
use App\Service\NonconformityService;
use Cake\Controller\Controller;
use Cake\Http\Exception\BadRequestException;

/** Deliberately independent of the authenticated/admin controller. No write actions. */
final class NonconformityConsultationsController extends Controller
{
    public function view(string $token): ?\Cake\Http\Response
    {
        $this->request->allowMethod(['get']);
        $this->response = $this->response->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Referrer-Policy', 'no-referrer')->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        try {
            $data = (new NonconformityConsultationService())->resolve($token);
        } catch (\Cake\Http\Exception\NotFoundException $e) {
            return $this->response->withStatus(404)->withType('html')->withStringBody('<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Consulta indisponível | PCM</title><h1>Consulta indisponível</h1><p>Link inválido ou expirado. Solicite um novo aviso à Qualidade.</p></html>');
        }
        $filters = [];
        foreach (['q', 'status', 'deadline'] as $key) {
            $value = $this->request->getQuery($key, '');
            if (!is_string($value) || mb_strlen($value) > 150) throw new BadRequestException('Filtro inválido.');
            $filters[$key] = trim($value);
        }
        if (($filters['status'] !== '' && !isset(NonconformityService::STATUSES[$filters['status']]))
            || !in_array($filters['deadline'], ['', 'overdue', 'today', 'soon', 'none'], true)) throw new BadRequestException('Filtro inválido.');
        ['record' => $record, 'action' => $action, 'responsible' => $responsible] = $data;
        $row = clone $record;
        foreach (['item', 'measure', 'responsible_name', 'responsible_id', 'due_on', 'completed_on', 'status', 'effectiveness', 'notes'] as $field) $row->set($field, $action->get($field));
        $row->set('action_id', $action->id)->set('action_description', $action->description)->set('linked_responsible_name', $responsible->name);
        $matches = $filters['q'] === '' || mb_stripos(implode(' ', [$record->number, $record->description, $record->unit, $action->item, $action->measure, $action->description, $responsible->name]), $filters['q']) !== false;
        $matches = $matches && ($filters['status'] === '' || $filters['status'] === $action->status);
        $due = NonconformityService::iso($action->due_on); $today = (new NonconformityService())->today();
        if ($filters['deadline'] !== '') $matches = $matches && $action->status !== 'completed' && match ($filters['deadline']) {
            'overdue' => $due !== null && $due < $today, 'today' => $due === $today,
            'soon' => $due !== null && $due >= $today && $due <= (new \DateTimeImmutable($today))->modify('+5 days')->format('Y-m-d'),
            'none' => $due === null,
        };
        $this->set(['rows' => $matches ? [$row] : [], 'filters' => $filters, 'canManage' => false, 'publicConsult' => true]);
        $this->viewBuilder()->setLayout('nonconformity_consultation');
        return null;
    }
}
