<?php
namespace BizyFiles;

use BizyCore\Services\Demo as Core_Demo;

defined( 'ABSPATH' ) || exit;

/**
 * BizyFiles' part of the sample data: a school-style library (classes, assignments, policies, a gallery, staff-only
 * notes) with real files made on the spot — PDFs, images, a sound, text and spreadsheets — and folder rules that use the
 * sample groups, so the sample login sees some folders and not others.
 */
class Demo {

	const OPTION = 'bizyfiles_demo_files';

	public static function init() {
		add_action( 'bizycore_demo_load', array( __CLASS__, 'load' ), 40 );
		add_action( 'bizycore_demo_cleared', array( __CLASS__, 'cleared' ) );
	}

	/* ───────────────────────── File makers ───────────────────────── */

	/** A small, valid, one-page PDF with a title and some lines. */
	public static function pdf( $title, array $lines ) {
		$esc  = function ( $t ) {
			return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), preg_replace( '/[^\x20-\x7e]/', '', $t ) );
		};
		$text = "BT /F1 22 Tf 56 770 Td (" . $esc( $title ) . ") Tj ET\n";
		$y    = 730;
		foreach ( $lines as $l ) {
			$text .= "BT /F1 12 Tf 56 $y Td (" . $esc( $l ) . ") Tj ET\n";
			$y    -= 20;
		}
		$objs   = array();
		$objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
		$objs[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>';
		$objs[] = '<< /Length ' . strlen( $text ) . " >>\nstream\n" . $text . 'endstream';
		$objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
		$pdf    = "%PDF-1.4\n";
		$offs   = array();
		foreach ( $objs as $i => $o ) {
			$offs[] = strlen( $pdf );
			$pdf   .= ( $i + 1 ) . " 0 obj\n" . $o . "\nendobj\n";
		}
		$x    = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offs as $o ) {
			$pdf .= sprintf( "%010d 00000 n \n", $o );
		}
		return $pdf . "trailer\n<< /Size " . ( count( $objs ) + 1 ) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
	}

	/** A coloured picture with a caption (PNG through GD; a plain colour block when GD is missing). */
	public static function png( $w, $h, array $rgb, $caption = '' ) {
		if ( function_exists( 'imagecreatetruecolor' ) ) {
			$im = imagecreatetruecolor( $w, $h );
			$c1 = imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] );
			$c2 = imagecolorallocate( $im, min( 255, $rgb[0] + 70 ), min( 255, $rgb[1] + 70 ), min( 255, $rgb[2] + 70 ) );
			imagefill( $im, 0, 0, $c1 );
			imagefilledellipse( $im, (int) ( $w * 0.7 ), (int) ( $h * 0.35 ), (int) ( $h * 0.7 ), (int) ( $h * 0.7 ), $c2 );
			imagefilledrectangle( $im, 0, (int) ( $h * 0.78 ), $w, $h, imagecolorallocate( $im, 255, 255, 255 ) );
			if ( '' !== $caption ) {
				imagestring( $im, 5, 14, (int) ( $h * 0.84 ), $caption, imagecolorallocate( $im, 30, 30, 40 ) );
			}
			ob_start();
			imagepng( $im );
			$out = ob_get_clean();
			imagedestroy( $im );
			return $out;
		}
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/** A short sine tone as a WAV (mono, 8 kHz, 16-bit). */
	public static function wav( $seconds = 2, $freq = 440 ) {
		$rate = 8000;
		$n    = $seconds * $rate;
		$data = '';
		for ( $i = 0; $i < $n; $i++ ) {
			$fade  = min( 1, $i / 400, ( $n - $i ) / 400 );
			$data .= pack( 'v', (int) ( 9000 * $fade * sin( 2 * M_PI * $freq * $i / $rate ) ) & 0xffff );
		}
		return 'RIFF' . pack( 'V', 36 + strlen( $data ) ) . 'WAVEfmt ' . pack( 'VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16 ) . 'data' . pack( 'V', strlen( $data ) ) . $data;
	}

	/* ───────────────────────── Loading ───────────────────────── */

	private static function folder( $parent, $name, $inherit, array $rules, $color = '' ) {
		$id = Core_Demo::add( 'bizyfiles_folders', array( 'parent_id' => $parent, 'name' => $name, 'color' => $color, 'inherit' => $parent ? (int) $inherit : 0 ) );
		foreach ( $rules as $r ) {
			Core_Demo::add( 'bizyfiles_access', array( 'folder_id' => $id, 'subject_type' => $r[0], 'subject_id' => $r[1], 'level' => $r[2] ) );
		}
		return $id;
	}

	private static function file( $folder, $name, $bytes, $description = '', $days_ago = 0 ) {
		$ext    = Store::ext( $name );
		$stored = bin2hex( random_bytes( 12 ) ) . '.' . $ext;
		file_put_contents( Store::dir() . '/' . $stored, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$when = gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
		$mime = Store::mime_for( $name );
		$id   = Core_Demo::add( 'bizyfiles_files', array( 'folder_id' => $folder, 'name' => $name, 'ext' => $ext, 'stored_name' => $stored, 'mime' => $mime, 'size' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ), 'version' => 1, 'description' => $description, 'uploaded_by' => get_current_user_id(), 'created_at' => $when, 'updated_at' => $when ) );
		Core_Demo::add( 'bizyfiles_versions', array( 'file_id' => $id, 'version' => 1, 'name' => $name, 'stored_name' => $stored, 'mime' => $mime, 'size' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ), 'uploaded_by' => get_current_user_id(), 'created_at' => $when ) );
		$list   = get_option( self::OPTION, array() );
		$list[] = $stored;
		update_option( self::OPTION, $list, false );
		return $id;
	}

	public static function load( $ctx ) {
		if ( ! \BizyCore\Registry::enabled( 'files' ) || empty( $ctx['groups'] ) ) {
			return;
		}
		delete_option( self::OPTION );
		list( $ga, $gb ) = $ctx['groups'];
		$clients         = $ctx['clients'];
		$team            = array( 'team', 0, 'manage' );

		$materials = self::folder( 0, 'Class Materials', 0, array( $team, array( 'group', $ga, 'view' ), array( 'group', $gb, 'view' ) ), '#2563eb' );
		$w1        = self::folder( $materials, 'Week 1', 1, array() );
		$w2        = self::folder( $materials, 'Week 2', 1, array() );
		$media     = self::folder( $materials, 'Audio & Video', 1, array() );
		$ass_a     = self::folder( 0, 'Class A Assignments', 0, array( $team, array( 'group', $ga, 'upload' ) ), '#16a34a' );
		$ass_b     = self::folder( 0, 'Class B Assignments', 0, array( $team, array( 'group', $gb, 'upload' ) ), '#d97706' );
		$policies  = self::folder( 0, 'School Policies', 0, array( $team, array( 'users', 0, 'view' ) ), '#7c3aed' );
		$gallery   = self::folder( 0, 'Gallery', 0, array( $team, array( 'users', 0, 'view' ) ), '#db2777' );
		$staff     = self::folder( 0, 'Staff Only', 0, array( $team ), '#6b7280' );
		self::folder( $staff, 'Exam Papers', 1, array() );

		self::file( $w1, 'Welcome and course outline.pdf', self::pdf( 'Welcome and course outline', array( 'Week 1 - Getting started', 'Week 2 - Core ideas', 'Week 3 - Practice and review', 'Bring your notebook to every class.' ) ), 'Read before the first class', 12 );
		self::file( $w1, 'Reading list.txt', "Reading list\n============\n1. Chapter 1 - Foundations\n2. Chapter 2 - Method\n3. Chapter 3 - Case studies\n", '', 12 );
		self::file( $w2, 'Worksheet 1.pdf', self::pdf( 'Worksheet 1', array( 'Name: ______________________', '1. Explain the main idea in your own words.', '2. Give two examples.', '3. What would you do differently?' ) ), 'Print and bring to class', 6 );
		self::file( $w2, 'Class notes.md', "# Class notes\n\n- Key ideas from week two\n- Questions to ask next time\n", '', 5 );
		self::file( $media, 'Pronunciation guide.wav', self::wav( 2, 440 ), 'A reference tone', 4 );
		self::file( $media, 'Warm-up exercise.wav', self::wav( 3, 330 ), '', 3 );
		self::file( $ass_a, 'Assignment 1 brief.pdf', self::pdf( 'Assignment 1 - Class A', array( 'Due on Friday.', 'Upload your finished work to this folder.', 'Late work loses one grade.' ) ), '', 2 );
		self::file( $ass_b, 'Assignment 1 brief.pdf', self::pdf( 'Assignment 1 - Class B', array( 'Due on Saturday.', 'Upload your finished work to this folder.' ) ), '', 2 );
		self::file( $policies, 'Student handbook.pdf', self::pdf( 'Student handbook', array( 'Attendance, conduct and safety.', 'Fees are due at the start of each term.', 'Ask the office if you need help.' ) ), '', 30 );
		self::file( $policies, 'Fee schedule.csv', "Item,Amount\nTuition,150000\nBooks,25000\nMaterials,10000\n", '', 30 );
		self::file( $policies, 'Term dates.txt', "Term 1: Jan - Apr\nTerm 2: May - Aug\nTerm 3: Sep - Dec\n", '', 30 );
		self::file( $gallery, 'Sports day.png', self::png( 640, 420, array( 37, 99, 235 ), 'Sports day' ), '', 20 );
		self::file( $gallery, 'Art show.png', self::png( 640, 420, array( 219, 39, 119 ), 'Art show' ), '', 15 );
		self::file( $gallery, 'Science fair.png', self::png( 640, 420, array( 22, 163, 74 ), 'Science fair' ), '', 9 );
		self::file( $gallery, 'Graduation.png', self::png( 640, 420, array( 217, 119, 6 ), 'Graduation' ), '', 3 );
		self::file( $staff, 'Staff meeting notes.txt', "Staff meeting\n- Review marking policy\n- Plan the open day\n", 'Not for students', 1 );
	}

	public static function cleared( $ids ) {
		foreach ( (array) get_option( self::OPTION, array() ) as $stored ) {
			@unlink( Store::dir() . '/' . basename( $stored ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		}
		delete_option( self::OPTION );
		Access::reset();
	}
}
