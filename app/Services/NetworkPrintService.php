<?php

namespace App\Services;

use App\Models\PrinterSetting;
use Exception;

class NetworkPrintService
{
    /**
     * Default timeout for socket connection in seconds.
     */
    protected const DEFAULT_TIMEOUT = 5;

    /**
     * Default port for Zebra printers (raw printing).
     */
    protected const DEFAULT_PORT = 9100;

    /**
     * Send ZPL data to a network printer.
     *
     * @throws Exception
     */
    public function print(PrinterSetting $printer, string $zpl): bool
    {
        if (! $printer->isNetworkPrintingEnabled()) {
            throw new Exception('Network printing is not configured for this printer.');
        }

        return $this->sendToAddress($printer->ip_address, $printer->port ?? self::DEFAULT_PORT, $zpl);
    }

    /**
     * Send ZPL data to a specific IP address and port.
     *
     * @throws Exception
     */
    public function sendToAddress(string $ipAddress, int $port, string $zpl): bool
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            throw new Exception('Could not create socket: '.socket_strerror(socket_last_error()));
        }

        // Set socket timeout
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => self::DEFAULT_TIMEOUT, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => self::DEFAULT_TIMEOUT, 'usec' => 0]);

        try {
            $connected = @socket_connect($socket, $ipAddress, $port);

            if (! $connected) {
                throw new Exception("Could not connect to printer at {$ipAddress}:{$port}. ".socket_strerror(socket_last_error($socket)));
            }

            $bytesSent = @socket_write($socket, $zpl, strlen($zpl));

            if ($bytesSent === false) {
                throw new Exception('Failed to send data to printer: '.socket_strerror(socket_last_error($socket)));
            }

            return true;
        } finally {
            socket_close($socket);
        }
    }

    /**
     * Check if a network printer is reachable.
     */
    public function isPrinterReachable(string $ipAddress, int $port = self::DEFAULT_PORT): bool
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            return false;
        }

        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 2, 'usec' => 0]);

        $connected = @socket_connect($socket, $ipAddress, $port);
        socket_close($socket);

        return $connected;
    }

    /**
     * Get printer status via ZPL host status request.
     * Returns printer status info or null if unable to retrieve.
     */
    public function getPrinterStatus(string $ipAddress, int $port = self::DEFAULT_PORT): ?array
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            return null;
        }

        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 2, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        try {
            if (! @socket_connect($socket, $ipAddress, $port)) {
                return null;
            }

            // Send Host Status Request (ZPL ~HS command)
            @socket_write($socket, '~HS', 3);

            // Read response
            $response = @socket_read($socket, 1024);

            if ($response === false || empty($response)) {
                return ['connected' => true, 'status' => 'unknown'];
            }

            return [
                'connected' => true,
                'status' => 'ready',
                'raw_response' => $response,
            ];
        } finally {
            socket_close($socket);
        }
    }
}
