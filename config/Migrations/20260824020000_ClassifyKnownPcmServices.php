<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class ClassifyKnownPcmServices extends BaseMigration
{
    public function up(): void
    {
        $this->execute("UPDATE services SET pcm_category = 'preventive', classification_version = 1 WHERE source_code IN ('PREVEN', 'ELEPRE')");
        $this->execute("UPDATE services SET pcm_category = 'improvement', classification_version = 1 WHERE source_code IN ('CALMEL', 'USIMEL', 'MECMAL', 'ELEMEL')");
        $this->execute("UPDATE services SET pcm_category = 'planned_special', classification_version = 1 WHERE source_code IN ('2425ME', '2425CA', 'PPRCAL', 'PPRINS')");
        $this->execute("UPDATE services SET pcm_category = 'corrective', classification_version = 1 WHERE source_code IN ('CORMEC', 'CORPRO', 'CORELE', 'CORCAL', 'CORUSI', 'COROPE', 'PROELE', 'COREME', 'CORINS', 'MECOPO', 'PROCAL', 'ELECOP', 'INSPRO')");
    }

    public function down(): void
    {
        $this->execute("UPDATE services SET pcm_category = NULL WHERE classification_version = 1 AND source_code IN ('PREVEN', 'ELEPRE', 'CALMEL', 'USIMEL', 'MECMAL', 'ELEMEL', '2425ME', '2425CA', 'PPRCAL', 'PPRINS', 'CORMEC', 'CORPRO', 'CORELE', 'CORCAL', 'CORUSI', 'COROPE', 'PROELE', 'COREME', 'CORINS', 'MECOPO', 'PROCAL', 'ELECOP', 'INSPRO')");
    }
}
