<?php
/**
 * Courier Center API Wrapper — OpenCart port
 * Accurate port of class-cc-api.php (WooCommerce plugin)
 * Replaces wp_remote_* with curl, WP_Error with array returns
 */

namespace Opencart\Extension\Couriercenter\Library;

class CCApi {

    private string $api_url = 'https://funship.cc.qualco.eu/ccservice/api';

    private array $credentials;

    public function __construct(string $user_alias, string $credential_value, string $api_key, string $billing_account) {
        $this->credentials = [
            'UserAlias'             => $user_alias,
            'CredentialValue'       => $credential_value,
            'ApiKey'                => $api_key,
            'CarrierBillingAccount' => $billing_account,
        ];
    }

    /**
     * Test API connection
     * Returns ['success'=>true, 'message'=>'...', 'data'=>[...]]
     */
    public function test_connection(): array {
        try {
            $response = $this->request('/Station/GetStations', [
                'Context' => $this->get_context(),
            ]);
            if (isset($response['StationDataInfo']) && is_array($response['StationDataInfo'])) {
                return [
                    'success' => true,
                    'message' => 'Η σύνδεση λειτουργεί! Βρέθηκαν ' . count($response['StationDataInfo']) . ' σταθμοί.',
                    'data'    => $response,
                ];
            }
            return [
                'success' => false,
                'message' => 'Το API απάντησε αλλά δεν επέστρεψε stations.',
                'data'    => $response,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Σφάλμα σύνδεσης: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Create a shipment
     * Returns full response array on success, or ['success'=>false, 'error'=>'...'] on failure
     */
    public function create_shipment(array $payload): array {
        try {
            $response = $this->request('/Shipment', $payload);

            if (isset($response['Result']) && $response['Result'] === 'Success') {
                return $response;
            }

            // Extract error message from various possible response shapes
            $error = 'Άγνωστο σφάλμα από το API';
            if (!empty($response['Errors'][0]['Message'])) {
                $error = $response['Errors'][0]['Message'];
            } elseif (!empty($response['ErrorMessage'])) {
                $error = $response['ErrorMessage'];
            } elseif (!empty($response['Message'])) {
                $error = $response['Message'];
            } elseif (!empty($response['ContractorResultNote'])) {
                $error = $response['ContractorResultNote'];
            }

            return ['success' => false, 'error' => $error, 'raw' => $response];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get voucher PDF
     * Returns raw PDF bytes string on success, or ['success'=>false, 'error'=>'...'] on failure
     */
    public function get_voucher_pdf(string $awb_numbers, string $template = 'cleanpdf'): string|array {
        $payload = [
            'context'        => $this->get_context(),
            'Template'       => $template,
            'ShipmentNumber' => $awb_numbers,
        ];

        $ch = curl_init($this->api_url . '/voucher');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            return ['success' => false, 'error' => $curl_err];
        }
        if ($http_code !== 200) {
            return ['success' => false, 'error' => "HTTP $http_code: " . substr($body, 0, 300)];
        }

        // Raw PDF bytes
        if (substr($body, 0, 4) === '%PDF') {
            return $body;
        }

        // JSON response with base64 PDF
        $json = json_decode($body, true);
        if ($json !== null) {
            foreach (['Voucher', 'PdfData', 'pdfData', 'Pdf', 'pdf', 'Data', 'data', 'Content', 'content'] as $field) {
                if (!empty($json[$field])) {
                    $decoded = base64_decode($json[$field], true);
                    if ($decoded !== false && substr($decoded, 0, 4) === '%PDF') {
                        return $decoded;
                    }
                }
            }
            $error = $json['ErrorMessage'] ?? $json['Message'] ?? 'Άγνωστο σφάλμα από το API voucher';
            return ['success' => false, 'error' => $error];
        }

        return ['success' => false, 'error' => 'Μη αναμενόμενο response από API voucher'];
    }

    /**
     * Void (cancel) a shipment
     * Returns true on success, or ['success'=>false, 'error'=>'...'] on failure
     */
    public function void_shipment(string $awb): bool|array {
        if (empty($awb)) {
            return ['success' => false, 'error' => 'Δεν δόθηκε AWB για ακύρωση'];
        }
        try {
            $response = $this->request('/Shipment/Void', [
                'Context'        => $this->get_context(),
                'ShipmentNumber' => $awb,
            ]);
            if (isset($response['Result']) && $response['Result'] === 'Success') {
                return true;
            }
            $error = $response['ErrorMessage'] ?? $response['Message'] ?? 'Αποτυχία ακύρωσης';
            return ['success' => false, 'error' => $error];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get shipment details / tracking status
     * Returns response array on success, or ['success'=>false, 'error'=>'...'] on failure
     */
    public function get_shipment_details(string $awb): array {
        if (empty($awb)) {
            return ['success' => false, 'error' => 'Δεν δόθηκε AWB'];
        }
        try {
            return $this->request('/Shipment/GetShipmentDetails', [
                'Context'    => $this->get_context(),
                'Identifier' => $awb,
            ]);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get manifest PDF for a date (YYYY-MM-DD)
     * Returns raw PDF bytes on success, or ['success'=>false, 'error'=>'...'] on failure
     */
    public function get_manifest(string $date): string|array {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return ['success' => false, 'error' => 'Μη έγκυρη ημερομηνία (YYYY-MM-DD)'];
        }

        $ch = curl_init($this->api_url . '/Manifest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['context' => $this->get_context(), 'Date' => $date]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) return ['success' => false, 'error' => $curl_err];
        if ($http_code !== 200) return ['success' => false, 'error' => "HTTP $http_code"];

        $json = json_decode($body, true);
        if (!$json) return ['success' => false, 'error' => 'Μη έγκυρο JSON response'];

        if (isset($json['Result']) && $json['Result'] === 'Failure') {
            $error = $json['Errors'][0]['Message'] ?? $json['ErrorMessage'] ?? 'Σφάλμα manifest';
            return ['success' => false, 'error' => $error];
        }
        if (empty($json['Manifest'])) {
            return ['success' => false, 'error' => 'Δεν υπάρχουν αποστολές για αυτή την ημερομηνία'];
        }

        $pdf = base64_decode($json['Manifest'], true);
        if ($pdf === false || substr($pdf, 0, 4) !== '%PDF') {
            return ['success' => false, 'error' => 'Μη έγκυρο PDF από API'];
        }
        return $pdf;
    }

    private function get_context(): array {
        return [
            'UserAlias'       => $this->credentials['UserAlias'],
            'CredentialValue' => $this->credentials['CredentialValue'],
            'ApiKey'          => $this->credentials['ApiKey'],
        ];
    }

    private function request(string $endpoint, array $body): array {
        $ch = curl_init($this->api_url . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) throw new \Exception($curl_err);
        if ($http_code !== 200) throw new \Exception("HTTP $http_code: $response");

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON: ' . json_last_error_msg());
        }
        return $data;
    }
}
