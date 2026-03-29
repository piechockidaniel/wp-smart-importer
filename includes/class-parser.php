<?php
defined( 'ABSPATH' ) || exit;

/**
 * Abstract base parser.
 * All parsers return a flat list of records (arrays) and a field schema.
 */
abstract class WPSI_Parser_Base {

    /** Parse raw string content into array of records. */
    abstract public function parse( string $content, string $root_path = '' ): array;

    /**
     * Extract a flat list of all unique field paths found in records.
     * Nested keys use dot notation: "author.name"
     */
    public function extract_fields( array $records, int $sample = 5 ): array {
        $paths = [];
        foreach ( array_slice( $records, 0, $sample ) as $record ) {
            foreach ( $this->flatten( $record ) as $path => $_ ) {
                $paths[ $path ] = true;
            }
        }
        return array_keys( $paths );
    }

    /** Flatten a nested array to dot-notation keys. */
    protected function flatten( mixed $node, string $prefix = '' ): array {
        $result = [];
        if ( is_array( $node ) ) {
            // Detect indexed array (list) vs associative
            if ( array_is_list( $node ) ) {
                // For lists we use [0], [1] etc. but cap at 3 to avoid huge schemas
                foreach ( array_slice( $node, 0, 3 ) as $i => $child ) {
                    $result = array_merge( $result, $this->flatten( $child, $prefix . "[{$i}]" ) );
                }
            } else {
                foreach ( $node as $key => $value ) {
                    $full = $prefix === '' ? $key : "{$prefix}.{$key}";
                    $result = array_merge( $result, $this->flatten( $value, $full ) );
                }
            }
        } else {
            $result[ $prefix ] = $node;
        }
        return $result;
    }

    /**
     * Read a value from a record by dot-notation path.
     * Supports: "author.name", "tags[0].label"
     */
    public static function get_value( array $record, string $path ): mixed {
        $segments = preg_split( '/\.|\[(\d+)\]/', $path, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
        $current  = $record;
        foreach ( $segments as $seg ) {
            if ( ! is_array( $current ) ) return null;
            if ( isset( $current[ $seg ] ) ) {
                $current = $current[ $seg ];
            } elseif ( is_numeric( $seg ) && isset( $current[ (int) $seg ] ) ) {
                $current = $current[ (int) $seg ];
            } else {
                return null;
            }
        }
        return $current;
    }

    /** Fetch remote URL with optional auth. Returns raw string or WP_Error. */
    public static function fetch_url( string $url, string $auth_type = 'none', string $auth_value = '' ): string|WP_Error {
        $args = [
            'timeout'   => 30,
            'sslverify' => true,
        ];

        if ( $auth_type === 'basic' ) {
            $args['headers']['Authorization'] = 'Basic ' . base64_encode( $auth_value );
        } elseif ( $auth_type === 'bearer' ) {
            $args['headers']['Authorization'] = 'Bearer ' . $auth_value;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'http_error', "HTTP {$code} from {$url}" );
        }

        return wp_remote_retrieve_body( $response );
    }

    /** Auto-detect file type from URL or Content-Type header. */
    public static function detect_type( string $url ): string {
        $path = strtolower( parse_url( $url, PHP_URL_PATH ) ?? '' );
        if ( str_ends_with( $path, '.xml' ) )  return 'xml';
        if ( str_ends_with( $path, '.csv' ) )  return 'csv';
        if ( str_ends_with( $path, '.json' ) ) return 'json';
        return 'json'; // default
    }
}

// ---------------------------------------------------------------------------
// JSON Parser
// ---------------------------------------------------------------------------
class WPSI_Parser_JSON extends WPSI_Parser_Base {

    public function parse( string $content, string $root_path = '' ): array {
        $data = json_decode( $content, true );
        if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
            throw new \RuntimeException( 'JSON parse error: ' . json_last_error_msg() );
        }

        if ( $root_path !== '' ) {
            $data = self::get_value( is_array( $data ) ? $data : [], $root_path );
            if ( ! is_array( $data ) ) {
                throw new \RuntimeException( "Root path '{$root_path}' did not resolve to an array." );
            }
        }

        // If root is associative (single object) wrap it
        if ( is_array( $data ) && ! array_is_list( $data ) ) {
            $data = [ $data ];
        }

        return $data ?? [];
    }
}

// ---------------------------------------------------------------------------
// XML Parser
// ---------------------------------------------------------------------------
class WPSI_Parser_XML extends WPSI_Parser_Base {

    public function parse( string $content, string $root_path = '' ): array {
        $prev = libxml_use_internal_errors( true );
        $xml  = simplexml_load_string( $content, 'SimpleXMLElement', LIBXML_NOCDATA );
        libxml_use_internal_errors( $prev );

        if ( $xml === false ) {
            $errors = libxml_get_errors();
            throw new \RuntimeException( 'XML parse error: ' . ( $errors[0]->message ?? 'unknown' ) );
        }

        $array = $this->xml_to_array( $xml );

        if ( $root_path !== '' ) {
            $array = self::get_value( $array, $root_path );
            if ( ! is_array( $array ) ) {
                throw new \RuntimeException( "Root path '{$root_path}' did not resolve to an array." );
            }
        }

        // Normalise: if the value under root is a single item (assoc), wrap it
        if ( is_array( $array ) && ! array_is_list( $array ) ) {
            $array = [ $array ];
        }

        return $array ?? [];
    }

    private function xml_to_array( \SimpleXMLElement $node ): array|string {
        $result = [];

        // Attributes
        foreach ( $node->attributes() as $key => $val ) {
            $result[ '@' . $key ] = (string) $val;
        }

        // Children
        foreach ( $node->children() as $name => $child ) {
            $converted = $this->xml_to_array( $child );
            if ( isset( $result[ $name ] ) ) {
                if ( ! is_array( $result[ $name ] ) || ! array_is_list( $result[ $name ] ) ) {
                    $result[ $name ] = [ $result[ $name ] ];
                }
                $result[ $name ][] = $converted;
            } else {
                $result[ $name ] = $converted;
            }
        }

        // Text content
        $text = trim( (string) $node );
        if ( $text !== '' ) {
            if ( empty( $result ) ) return $text;
            $result['_text'] = $text;
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// CSV Parser
// ---------------------------------------------------------------------------
class WPSI_Parser_CSV extends WPSI_Parser_Base {

    public function parse( string $content, string $root_path = '' ): array {
        // Normalise line endings
        $content = str_replace( "\r\n", "\n", $content );
        $content = str_replace( "\r",   "\n", $content );

        $lines = explode( "\n", trim( $content ) );
        if ( count( $lines ) < 2 ) {
            throw new \RuntimeException( 'CSV must have a header row and at least one data row.' );
        }

        $delimiter = $this->detect_delimiter( $lines[0] );
        $headers   = str_getcsv( array_shift( $lines ), $delimiter );
        $headers   = array_map( 'trim', $headers );

        $records = [];
        foreach ( $lines as $line ) {
            if ( trim( $line ) === '' ) continue;
            $values = str_getcsv( $line, $delimiter );
            $record = [];
            foreach ( $headers as $i => $header ) {
                $record[ $header ] = $values[ $i ] ?? '';
            }
            $records[] = $record;
        }

        return $records;
    }

    private function detect_delimiter( string $sample ): string {
        $counts = [
            ','  => substr_count( $sample, ',' ),
            ';'  => substr_count( $sample, ';' ),
            "\t" => substr_count( $sample, "\t" ),
            '|'  => substr_count( $sample, '|' ),
        ];
        arsort( $counts );
        return array_key_first( $counts );
    }
}

// ---------------------------------------------------------------------------
// Factory
// ---------------------------------------------------------------------------
class WPSI_Parser {

    public static function make( string $type ): WPSI_Parser_Base {
        return match ( $type ) {
            'xml'   => new WPSI_Parser_XML(),
            'csv'   => new WPSI_Parser_CSV(),
            default => new WPSI_Parser_JSON(),
        };
    }
}
