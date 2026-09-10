<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\CurrentSnapshotService;
use App\Service\Import\ReportFileProcessor;
use Cake\Log\Log;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

final class ReportImportsController extends AppController
{
    public function index(): void
    {
        $imports = $this->paginate($this->fetchTable('ReportImports')->find()->orderByDesc('started_at'), ['limit' => 25]);
        $snapshot = new CurrentSnapshotService();
        $currentImport = $snapshot->currentImport();
        $navigationAreas = $snapshot->areas();
        $this->set(compact('imports', 'currentImport', 'navigationAreas'));
    }

    public function manual()
    {
        $snapshot = new CurrentSnapshotService();
        $navigationAreas = $snapshot->areas();
        $this->set(compact('navigationAreas'));

        if ($this->request->is('post')) {
            $upload = $this->request->getData('report_file');
            if (!$upload instanceof UploadedFileInterface) {
                $this->Flash->error('Selecione um arquivo XLSX.');

                return null;
            }
            try {
                $import = (new ReportFileProcessor())->processUpload($upload);
                $this->Flash->success(sprintf(
                    'Relatório importado com sucesso. %d registros processados.',
                    $import->rows_imported,
                ));

                return $this->redirect(['action' => 'index']);
            } catch (Throwable $exception) {
                Log::error((string)$exception);
                if (str_contains($exception->getMessage(), 'já foi importado')) {
                    $this->Flash->error('Este relatório já foi importado anteriormente.');
                } else {
                    $this->Flash->error('Não foi possível importar o relatório.');
                }
            }
        }

        return null;
    }
}
