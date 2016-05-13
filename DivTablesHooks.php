<?php

class DivTablesHooks {
	public static function onBeforePageDisplay( $out, $skin ) {
		$out->addModuleStyles( 'ext.divtables' );
		return true;
	}
	
	public static function onParserBeforeStrip( &$parser, &$text, &$mStripState ) {
		$title = $parser->getTitle();
		if($title->getNamespace() < NS_MAIN) {
			return true;
		}
		$article = WikiPage::factory($title);
		$text = DivTablesHooks::doTableStuff($text, $mStripState);
		
		return true;
	}
	
	/**
	 * parse the wiki syntax used to render tables
	 *
	 * @private
	 * @param string $text
	 * @return string
	 *
	 * this is a modified version of Parser::doTableStuff
	 */
	public static function doTableStuff( $text, $mStripState ) {

		$lines = StringUtils::explode( "\n", $text );
		$out = '';
		$td_history = array(); # Is currently a td tag open?
		$last_tag_history = array(); # Save history of last lag activated (td, th or caption)
		$tr_history = array(); # Is currently a tr tag open?
		$tr_attributes = array(); # history of tr attributes
		$has_opened_tr = array(); # Did this table open a <tr> element?
		$indent_level = 0; # indent level of the table

		foreach ( $lines as $outLine ) {
			$line = trim( $outLine );

			if ( $line === '' ) { # empty line, go to next line
				$out .= $outLine . "\n";
				continue;
			}

			$first_character = $line[0];
			$matches = array();

			if ( preg_match( '/^(:*)\{\|(.*)$/', $line, $matches ) ) {
				# First check if we are starting a new table
				$indent_level = strlen( $matches[1] );

				$attributes = $mStripState->unstripBoth( $matches[2] );
				$attributes = Sanitizer::fixTagAttributes( $attributes, 'table' );
				
				if(strpos($attributes, 'class')) {
					$attributes = str_replace('class="', 'class="div-table ', $attributes);
				} else {
					$attributes = $attributes . ' class="div-table"';
				}

				$outLine = str_repeat( '<dl><dd>', $indent_level ) . "<div{$attributes}>";
				array_push( $td_history, false );
				array_push( $last_tag_history, '' );
				array_push( $tr_history, false );
				array_push( $tr_attributes, '' );
				array_push( $has_opened_tr, false );
			} elseif ( count( $td_history ) == 0 ) {
				# Don't do any of the following
				$out .= $outLine . "\n";
				continue;
			} elseif ( substr( $line, 0, 2 ) === '|}' ) {
				# We are ending a table
				$line = '</div>' . substr( $line, 2 );
				$last_tag = array_pop( $last_tag_history );

				if ( !array_pop( $has_opened_tr ) ) {
					$line = "<div class=\"div-table-row\"><div class=\"div-table-cell\"></div></div>{$line}";
				}

				if ( array_pop( $tr_history ) ) {
					$line = "</div>{$line}";
				}

				if ( array_pop( $td_history ) ) {
					$line = "</div>{$line}";
				}
				array_pop( $tr_attributes );
				$outLine = $line . str_repeat( '</dd></dl>', $indent_level );
			} elseif ( substr( $line, 0, 2 ) === '|-' ) {
				# Now we have a table row
				$line = preg_replace( '#^\|-+#', '', $line );

				# Whats after the tag is now only attributes
				$attributes = $mStripState->unstripBoth( $line );
				$attributes = Sanitizer::fixTagAttributes( $attributes, 'tr' );
				array_pop( $tr_attributes );
				array_push( $tr_attributes, $attributes );

				$line = '';
				$last_tag = array_pop( $last_tag_history );
				array_pop( $has_opened_tr );
				array_push( $has_opened_tr, true );

				if ( array_pop( $tr_history ) ) {
					$line = '</div>';
				}

				if ( array_pop( $td_history ) ) {
					$line = "</div>{$line}";
				}

				$outLine = $line;
				array_push( $tr_history, false );
				array_push( $td_history, false );
				array_push( $last_tag_history, '' );
			} elseif ( $first_character === '|'
				|| $first_character === '!'
				|| substr( $line, 0, 2 ) === '|+'
			) {
				# This might be cell elements, td, th or captions
				if ( substr( $line, 0, 2 ) === '|+' ) {
					$first_character = '+';
					$line = substr( $line, 1 );
				}

				$line = substr( $line, 1 );

				if ( $first_character === '!' ) {
					$line = str_replace( '!!', '||', $line );
				}

				# Split up multiple cells on the same line.
				# FIXME : This can result in improper nesting of tags processed
				# by earlier parser steps, but should avoid splitting up eg
				# attribute values containing literal "||".
				$cells = StringUtils::explodeMarkup( '||', $line );

				$outLine = '';

				# Loop through each table cell
				foreach ( $cells as $cell ) {
					$previous = '';
					if ( $first_character !== '+' ) {
						$tr_after = array_pop( $tr_attributes );
						if(strpos($tr_after, 'class')) {
							$tr_after = str_replace('class="', 'class="div-table-row ', $tr_after);
						} else {
							$tr_after = $tr_after . ' class="div-table-row"';
						}
						if ( !array_pop( $tr_history ) ) {
							$previous = "<div{$tr_after}>\n";
						}
						array_push( $tr_history, true );
						array_push( $tr_attributes, '' );
						array_pop( $has_opened_tr );
						array_push( $has_opened_tr, true );
					}

					$last_tag = array_pop( $last_tag_history );

					if ( array_pop( $td_history ) ) {
						$previous = "</div>\n{$previous}";
					}

					if ( $first_character === '|' ) {
						$last_tag = 'td';
					} elseif ( $first_character === '!' ) {
						$last_tag = 'th';
					} elseif ( $first_character === '+' ) {
						$last_tag = 'caption';
					} else {
						$last_tag = '';
					}

					array_push( $last_tag_history, $last_tag );

					# A cell could contain both parameters and data
					$cell_data = explode( '|', $cell, 2 );

					# Bug 553: Note that a '|' inside an invalid link should not
					# be mistaken as delimiting cell parameters
					if ( strpos( $cell_data[0], '[[' ) !== false ) {
						$cell = "{$previous}<div class=\"".($last_tag == "caption"?"div-table-cell-caption ":"")." ".($last_tag == "th"?"div-table-cell-header ":"")." div-table-cell\">{$cell}";
					} elseif ( count( $cell_data ) == 1 ) {
						$cell = "{$previous}<div class=\"".($last_tag == "caption"?"div-table-cell-caption ":"")." ".($last_tag == "th"?"div-table-cell-header ":"")." div-table-cell\">{$cell_data[0]}";
					} else {
						$attributes = $mStripState->unstripBoth( $cell_data[0] );
						$attributes = Sanitizer::fixTagAttributes( $attributes, $last_tag );
						
						if(strpos($attributes, 'class')) {
							$attributes = str_replace('class="', 'class="div-table-cell ', $attributes);
						} else {
							$attributes = $attributes . ' class="div-table-cell"';
						}
						
						if($last_tag == 'th') {
							if(strpos($attributes, 'class')) {
								$attributes = str_replace('class="', 'class="div-table-cell-header ', $attributes);
							} else {
								$attributes = $attributes . ' class="div-table-cell-header"';
							}
						}
						
						if($last_tag == 'caption') {
							if(strpos($attributes, 'class')) {
								$attributes = str_replace('class="', 'class="div-table-cell-caption ', $attributes);
							} else {
								$attributes = $attributes . ' class="div-table-cell-caption"';
							}
						}
						
						$cell = "{$previous}<div{$attributes}>{$cell_data[1]}";
					}

					$outLine .= $cell;
					array_push( $td_history, true );
				}
			}
			$out .= $outLine . "\n";
		}

		# Closing open td, tr && table
		while ( count( $td_history ) > 0 ) {
			if ( array_pop( $td_history ) ) {
				$out .= "</div>\n";
			}
			if ( array_pop( $tr_history ) ) {
				$out .= "</div>\n";
			}
			if ( !array_pop( $has_opened_tr ) ) {
				$out .= "<div class=\"div-table-row\"><div class=\"div-table-cell\"></div></div>\n";
			}

			$out .= "</div>\n";
		}

		# Remove trailing line-ending (b/c)
		if ( substr( $out, -1 ) === "\n" ) {
			$out = substr( $out, 0, -1 );
		}

		# special case: don't return empty table
		if ( $out === "<div class=\"div-table\">\n<div class=\"div-table-row\"><div class=\"div-table-cell\"></div></div>\n</div>" ) {
			$out = '';
		}

		return $out;
	}
}

?>