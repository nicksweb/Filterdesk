<?php

namespace App\Http\Controllers;

use App\Services\Network\InspectionCertificate;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public page (no sign-in: a device may need the certificate before it can
 * reach anything) with the HTTPS inspection certificate and how to install it.
 */
class CertificateController extends Controller
{
    public function show(InspectionCertificate $certificate): View
    {
        abort_unless($certificate->current(), 404);

        return view('certificate', ['certificate' => $certificate->current()]);
    }

    public function download(InspectionCertificate $certificate, string $format): Response
    {
        abort_unless($certificate->current(), 404);

        return $format === 'pem'
            ? response($certificate->current()['pem'], 200, ['Content-Type' => 'application/x-pem-file', 'Content-Disposition' => 'attachment; filename="school-inspection-ca.pem"'])
            : response($certificate->der(), 200, ['Content-Type' => 'application/x-x509-ca-cert', 'Content-Disposition' => 'attachment; filename="school-inspection-ca.crt"']);
    }
}
