<?php
/**
 * TEN Venues Database Connection Helper
 * 
 * This file provides a connection function to the TEN_Venues database
 * for use within TEN Management when managing the Venue Management tool.
 * 
 * TEN Management and TEN Venues are separate databases but share the same server.
 */

if (!function_exists('getVenuesDBConnection')) {
    function getVenuesDBConnection() {
        // TEN_Venues database credentials
        $host = 'localhost';
        $user = 'ten_venues_7462';
        $pass = ']yzXYYKg(BpJ)-8Q';
        $dbname = 'TEN_Venues';
        
        $conn = new mysqli($host, $user, $pass, $dbname);
        
        if ($conn->connect_error) {
            error_log("TEN_Venues database connection failed: " . $conn->connect_error);
            return null;
        }
        
        $conn->set_charset("utf8mb4");
        return $conn;
    }
}
