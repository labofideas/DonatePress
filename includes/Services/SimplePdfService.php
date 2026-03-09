<?php

namespace DonatePress\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal PDF renderer for plain-text receipts.
 */
class SimplePdfService {
	/**
	 * Render UTF-8 text content into a very small single-page PDF.
	 */
	public function render_text_pdf( string $text, string $title = 'DonatePress Receipt' ): string {
		$lines = preg_split( "/\r\n|\r|\n/", $text ) ?: array();
		$lines = array_values(
			array_filter(
				array_map(
					static function ( $line ): string {
						$line = wp_strip_all_tags( (string) $line );
						$line = preg_replace( '/[^\x20-\x7E]/', '?', $line );
						return is_string( $line ) ? trim( $line ) : '';
					},
					$lines
				),
				static fn( string $line ): bool => '' !== $line
			)
		);

		if ( empty( $lines ) ) {
			$lines = array( 'DonatePress Receipt' );
		}

		$stream = "BT\n/F1 12 Tf\n50 770 Td\n";
		$first  = true;
		foreach ( $lines as $line ) {
			$escaped = str_replace(
				array( '\\', '(', ')' ),
				array( '\\\\', '\(', '\)' ),
				$line
			);

			if ( $first ) {
				$stream .= sprintf( "(%s) Tj\n", $escaped );
				$first = false;
			} else {
				$stream .= sprintf( "0 -16 Td\n(%s) Tj\n", $escaped );
			}
		}
		$stream .= "ET\n";

		$objects   = array();
		$objects[] = '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj';
		$objects[] = '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj';
		$objects[] = '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj';
		$objects[] = '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj';
		$objects[] = sprintf(
			'5 0 obj << /Length %d >> stream' . "\n" . '%sendstream endobj',
			strlen( $stream ),
			$stream
		);
		$objects[] = sprintf(
			'6 0 obj << /Title (%s) /Producer (DonatePress) >> endobj',
			str_replace( array( '\\', '(', ')' ), array( '\\\\', '\(', '\)' ), $title )
		);

		$pdf     = "%PDF-1.4\n";
		$offsets = array( 0 );
		foreach ( $objects as $object ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= $object . "\n";
		}

		$xref_start = strlen( $pdf );
		$pdf       .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n";
		$pdf       .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= count( $objects ); $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R /Info 6 0 R >>\n";
		$pdf .= "startxref\n{$xref_start}\n%%EOF";

		return $pdf;
	}
}
