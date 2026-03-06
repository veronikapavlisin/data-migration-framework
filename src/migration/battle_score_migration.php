<?php

$is_cli = 'cli' === \PHP_SAPI;
if( $is_cli ) {
	$opt = \getopt( '', [ 'dry:', ] );
	$dry_run = ( bool )( $opt[ 'dry' ] ?? true );
} else {
	$dry_run = ( bool )( $_GET[ 'dry' ] ?? true );
	$child_run = ( bool )( $_GET[ 'child' ] ?? false );
}

require '../../inc/bootstrap.php';

// dry run hint
if( $dry_run ) {
	echo 'dry run, please '
		. ( 'cli' === \PHP_SAPI ? 'pass --dry=0' : 'add get parameter dry=0' )
		. ' if you want to run the actual update'
		. \EOL;
}

$game_info = [
	// DO EU 1
	/*
	'do' =>[
		'ae' => $game_info[ 'do' ][ 'ae' ],
		'de' => $game_info[ 'do' ][ 'de' ],
	    ...
	    ...
	    ...
	],
	*/
	// DO EU 2
	/*
	    examples of all realms in all servers

	    ...
	    ...
	    ...

	*/
];

/*$game_info = [
	'do' => [
		'local' => [
			1 => [
				'name' => 'Charlie',
				'ip' => 'desertops_mdb',
				'db' => 'do_beta_1',
				'type' => 'endless',
				'server' => 'world1',
				'path' => 'world1',
				'timezone' => 'Europe/Berlin',
			],
		],
	],
];*/

/**
 * Get config by name
 *
 * @param mysqli $mysqli
 * @param string $name
 * @param string $realm
 * @return string
 *
 * @throws \Exception
 */
function fetch_config(
	\mysqli $mysqli,
	string $name,
	string $realm = ''
): string {
	$cache_name = $name;
	if ( ! empty( $realm ) ) {
		$cache_name = $realm . '_' . $name;
	}
	// some caching
	static $config_cache = [];
	if ( isset( $config_cache[ $cache_name ] ) ) {
		return $config_cache[ $cache_name ];
	}
	// get start reward
	$config_result = $mysqli->query( '
		SELECT
			`value`
		FROM
			`config`
		WHERE
			`name` = "' . $mysqli->real_escape_string( $name ) . '"'
	);
	// handle error
	if ( false === $config_result ) {
		throw new \Exception( 'Unable to query config "' . $name . '"' );
	}
	// fetch
	$config_data = $config_result->fetch_assoc();
	// handle error
	if ( empty( $config_data ) ) {
		throw new \Exception( 'Unable to fetch config "' . $name . '"' );
	}
	// fill cache and return
	$config_cache[ $cache_name ] = ( string )$config_data[ 'value' ];
	unset( $config_data );
	return $config_cache[ $cache_name ];
}

/**
 * Helper to check if event is running
 *
 * @param mysqli $mysqli
 * @param ...$event
 * @return bool
 *
 * @throws Exception
 */
function is_event_running( \mysqli $mysqli, ...$event ): bool {
	// check if event exists
	$check_result = $mysqli->query( '
		SELECT
			IFNULL( (
				SELECT
					COUNT( `eventID` ) AS `count`
				FROM
					`game_event`
				WHERE
					`eventName` IN("'
						. \implode(
							'","',
							\array_map(
								[ $mysqli, 'real_escape_string', ],
								$event
							)
						)
					. '")
				),
				0
			) AS `count`
	' );
	if ( ! $check_result ) {
		throw new \Exception( 'Event check query failed!' );
	}
	$check_data = $check_result->fetch_assoc();
	if( ! $check_data ) {
		throw new \Exception( 'Event check fetch failed!' );
	}
	return 0 < ( int )$check_data[ 'count' ];
}

/**
 * Helper to calculate battle points
 *
 * @param mysqli $mysqli
 * @param bool $won_battle
 * @param int $bash_level
 * @param bool $is_attacker
 * @param string $points_attacker
 * @param string $points_defender
 * @param string $realm
 * @return string
 *
 * @throws Exception
 */
function calculate_battle_score(
	\mysqli $mysqli,
	bool $won_battle,
	int $bash_level,
	bool $is_attacker,
	string $points_attacker,
	string $points_defender,
	string $realm = ''
): string {
	// fetch configs
	$attack_grenze_unten = \fetch_config(
		$mysqli,
		'CONFIG_ATTACK_RATIO_MIN',
		$realm
	);
	$battle_winning_points = \fetch_config(
		$mysqli,
		'CONFIG_BATTLE_WIN_POINTS',
		$realm
	);
	$battle_loose_attacker_points = \fetch_config(
		$mysqli,
		'CONFIG_ATTACKER_LOSS_POINTS',
		$realm
	);
	$battle_loose_defender_points = \fetch_config(
		$mysqli,
		'CONFIG_DEFENDER_LOSS_POINTS',
		$realm
	);
	// handle not won
	if ( true !== $won_battle ) {
		return \bcsub(
			0,
			true === $is_attacker
				? $battle_loose_attacker_points
				: $battle_loose_defender_points
		);
	}

	/*
	 * Determine max/min point ratios for battle highscore calculation
	 * to limit the points given for a fight to the normal values
	 */
	$point_ratio_min = $attack_grenze_unten;
	$point_ratio_max = 1 / $point_ratio_min;
	// defender bash level always 2
	$bash_level_calculation = 2;
	// different handling for attacker
	if ( true === $is_attacker ) {
		$bash_level_calculation = ( 10 - \max( $bash_level, 0 ) ) / 10 + 1;
		// cap
		if ( $bash_level_calculation < 1 ) {
			$bash_level_calculation = 1;
		} elseif ( $bash_level_calculation > 2 ) {
			$bash_level_calculation = 2;
		}
	}
	// give or take attacker points
	$point_ratio = $is_attacker
		? \bcdiv_safe( $points_defender, $points_attacker, 2 )
		: \bcdiv_safe( $points_attacker, $points_defender, 2 );
	if ( 1 == \bccomp( $point_ratio_min, $point_ratio, 2 ) ) {
		$point_ratio = $point_ratio_min;
	} elseif ( 1 == \bccomp( $point_ratio, $point_ratio_max, 2 ) ) {
		$point_ratio = $point_ratio_max;
	}
	return \bcadd(
		$battle_winning_points,
		\bcmul(
			\bcmul( $point_ratio, 100, 2 ),
			$bash_level_calculation,
			2
		),
		2
	);
}

$limit = 10000;
$progress_limit = $limit / 10;
// db unique check
$db_unique = [];

$continue_flag = false;
$overall_count = 0;

if ( ! $dry_run && ! $is_cli ) {
	if ( ! $child_run ) {

		$child_url = $_SERVER[ 'REQUEST_SCHEME' ] . '://' . $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ] . '&child=1';
		echo '<HTML>'
			. 	'<HEAD>'
			.		'<SCRIPT type="text/javascript">
						let content_count = 0;
                        let change_time = Math.floor( Date.now() / 1000 );
                        let delta_time = 0;
                        function checkChildProgress() {
                            let iframe_object = document.getElementById( "calculate_action_battle_score_child_frm" ).contentWindow.document;
                            if ( content_count !== iframe_object.body.innerHTML.length ) {
                                content_count = iframe_object.body.innerHTML.length;
                                change_time = Math.floor( Date.now() / 1000 );
                                delta_time = 0;
                            } else {
                                delta_time = Math.floor( Date.now() / 1000 ) - change_time;
                                // if nothing changed for 5 minutes
                                if ( delta_time > 5*60 ) {
                                    let content_string = iframe_object.body.innerHTML;
                                    // there are no errors, processing was not interrupted
                                    // and there is anything to process
                                    if (
                                        -1 === content_string.indexOf( "Done. Continuous processing cancelled." )
                                        && -1 === content_string.indexOf( "<red>" )
                                        && -1 === content_string.indexOf( "Done. Too little rows to process." )
                                    ) {
                                        // get name of realm
                                        let tmp = content_string.split( "WORLD" );
                                        let tmp2 = tmp[ tmp.length - 2 ].split( ">" );
                                        let tmp3 = tmp[ tmp.length - 1 ].split( "<" );
                                        let restarted_realm = tmp2[ tmp2.length - 1 ] + "WORLD" + tmp3[ 0 ];

                                        // restart
                                        document.getElementById( "calculate_action_battle_score_child_frm" ).contentWindow.location.reload();
                                        if ( "" === document.getElementById( "restart_span" ).innerHTML ) {
                                            document.getElementById( "restart_span" ).innerHTML = "Restarted at:";
                                        }
                                        document.getElementById( "restart_span" ).innerHTML += "<br/>"
                                            + ( new Date().toLocaleString() )
                                            + " "
                                            + restarted_realm;
                                    } else {
                                        return;
                                    }
                                }
                            }
                            document.getElementById( "count_span" ).innerText = content_count;
                            document.getElementById( "lag_span" ).innerText = delta_time + "s";
                            setTimeout( function () { checkChildProgress() }, 3000 );
                        }
                        setTimeout( function () { checkChildProgress() }, 5000 );
					'
			.		'</SCRIPT>'
			. 	'</HEAD>'
			.	'<BODY>'
			.		'<SPAN id="restart_span"></SPAN>'
			.		'<DIV style="text-align: right">Characters: <SPAN id="count_span">0</SPAN> Last change in: <SPAN id="lag_span">0</SPAN></DIV>'
			. 		'<IFRAME width=100% height=100% id="calculate_action_battle_score_child_frm" src="' . $child_url . '" scrolling="yes" frameborder="1" style="visibility:visible; border:1px solid black;"></IFRAME>'
			.	'</BODY>';
		exit;
	}

	echo '<form action="" method="get" accept-charset="UTF-8">'
		. '<div>Continuous processing <input type="checkbox" id="continue" value="1" checked></div>'
		. '</form>';
}

foreach( $game_info as $game => $game_data ) {
	foreach( $game_data as $country => $country_data ) {
		foreach( $country_data as $world => $world_data ) {
			if(
				// skip multi use schema
				\in_array( $world_data[ 'db' ], $db_unique )
				// skip tutorial realm
				|| 'tutorial' === $world_data[ 'type' ]
				// skip child realms
				|| $world_data[ 'parent' ]
			) {
				continue;
			}
			// print world name
			echo \query_output( 'default', $world_data[ 'name' ] );
			for ( $i = 0; $i < 20; $i++ ) {
				echo \query_output( 'default', '..........' );
			}
			echo \query_output( 'default', \EOL );
			\flush(); \ob_flush(); \flush();
			// connect
			require \ROOT . 'inc' . \DS . 'connect.php';
			if ( $mysqli->connect_errno ) {
				echo \query_output( 'failed', 'Connection cannot be established.' . \EOL );
				\flush();\ob_flush();\flush();
				continue;
			}
			// push to db unique
			$db_unique[] = $world_data[ 'db' ];

			// check for running event
			try {
				if ( \is_event_running( $mysqli, 'attackRangePlayer' ) ) {
					echo \query_output(
						'warn',
						'Event attackRangePlayer active, skipping realm' . \EOL
					);
					\flush();\ob_flush();\flush();
					// close connection and continue
					$mysqli->close();
					continue;
				}
			} catch ( \Exception $exception ) {
				echo \query_output(
					'failed',
					\EOL . 'Error while checking for active event: '
						. $exception->getMessage() . \EOL
						. (
							'cli' === \PHP_SAPI
								? $exception->getTraceAsString()
								: '<pre>' . $exception->getTraceAsString() . '</pre>'
						)
				);
				\flush();\ob_flush();\flush();
				// close connection and continue
				$mysqli->close();
				continue;
			}

			// start transaction
			if( ! $dry_run ) {
				// set autocommit to false
				$mysqli->autocommit( false );
			}
			// get last handled military action id
			$last_handled_military_action_id = ( int )\fetch_config(
				$mysqli,
				'CONFIG_LAST_PROCESSED_ACTION_ID',
				$world_data[ 'name' ]
			);
			// select all actions where loot transferred is true
			$count_result = $mysqli->query( '
				SELECT COUNT(id) AS count
                FROM battle_actions
                WHERE id > :last_processed
                ORDER BY id'
			);

			$row = $count_result->fetch_assoc();
			echo \query_output( 'default', 'Records to process overall: ' . $row[ 'count' ] );
			$overall_count += $row[ 'count' ];
			\flush(); \ob_flush(); \flush();

			$query = '
				SELECT
                    id,
                    attacker_id,
                    defender_id,
                    attacker_points,
                    defender_points,
                    battle_result
                FROM battle_actions
                WHERE id > :last_processed
                ORDER BY id
                LIMIT :batch_size;

			// select all actions where loot transferred is true
			try {
				$action_result = $mysqli->query( $query );
			} catch ( \Exception $action_exception ) {}

			if ( false === $action_result || isset( $action_exception ) ) {
				$error_info = '';
				if ( isset( $action_exception ) ) {
					$error_info = $action_exception->getMessage()
						. \EOL
						. (
						'cli' === \PHP_SAPI
							? $action_exception->getTraceAsString()
							: '<pre>' . $action_exception->getTraceAsString() . '</pre>'
						);
				} else {
					$error_info = \EOL . 'Query: ' . \EOL . $query . \EOL . \EOL
						. 'Error: ' . $mysqli->error;
				}
				echo \query_output(
					'failed',
					\EOL . 'Error while fetching military action data.' . \EOL . $error_info
				);
				\flush();\ob_flush();\flush();
				// rollback and set autocommit back to true
				if( ! $dry_run ) {
					$mysqli->rollback();
					$mysqli->autocommit( true );
				}
				$mysqli->close();
				continue;
			}
			// setup user exist cache
			$progress_count = 0;
			$progress_last = 0;
			$porgress_time = \microtime( true );
			$user_exist = [];
			$new_handled_military_action_id = 0;
			// done indicator
			if( 0 >= $action_result->num_rows ) {
				echo \EOL . \query_output( 'success', 'done' ) . \EOL;
				\flush(); \ob_flush(); \flush();
			}
			// loop through data
			while ( $row = $action_result->fetch_assoc() ) {
				++$progress_count;
				try {
					// decode owner and target score
					$owner_score = \json_decode(
						$row[ 'owner_score' ],
						true,
						512,
						\JSON_THROW_ON_ERROR
					);
					$target_score = \json_decode(
						$row[ 'target_score' ],
						true,
						512,
						\JSON_THROW_ON_ERROR
					);
					// populate start points
					$owner_score[ 'player_start' ] = $row[ 'owner_start_points' ];
					$target_score[ 'player_start' ] = $row[ 'target_start_points' ];
					if (
						'Lasso\\Core\\Hook\\Request\\User' === $row[ 'owner_type' ]
						&& 'Lasso\\Core\\Hook\\Request\\User' === $row[ 'target_type' ]
					) {
						// retrieve value from cache and set if not found in previous query
						$row[ 'owner_exists' ] = 0 < ( int )$row[ 'attacker_id' ];
						$row[ 'target_exists' ] = 0 < ( int )$row[ 'defender_id' ];
						// calculate if both are existing
						if ( $row[ 'owner_exists' ] && $row[ 'target_exists' ] ) {
							$owner_score[ 'battle_score' ] = \calculate_battle_score(
								$mysqli,
								'won' === $row[ 'owner_won_lost_status' ],
								$row[ 'bashLevel' ],
								true,
								$row[ 'owner_before_calculated' ],
								$row[ 'target_before_calculated' ],
								$world_data[ 'name' ]
							);
							$target_score[ 'battle_score' ] = \calculate_battle_score(
								$mysqli,
								'won' === $row[ 'target_won_lost_status' ],
								$row[ 'bashLevel' ],
								false,
								$row[ 'owner_before_calculated' ],
								$row[ 'target_before_calculated' ],
								$world_data[ 'name' ]
							);
						}
						// push back points before calculated to json
						$owner_score[ 'player_before_calculated' ] = $row[ 'owner_before_calculated' ];
						$target_score[ 'player_before_calculated' ] = $row[ 'target_before_calculated' ];
					}
					// encode to json again
					$owner_score = \json_encode( $owner_score, \JSON_THROW_ON_ERROR );
					$target_score = \json_encode( $target_score, \JSON_THROW_ON_ERROR );
					// build update query
					$update_military_action = '
						UPDATE battle_actions
        SET attacker_score = :attacker_score,
            defender_score = :defender_score
        WHERE id = ' . ( int )$row[ 'id' ];
					// echo query during dry run
					if ( $dry_run ) {
						echo $update_military_action . \EOL;
						\flush();\ob_flush();\flush();
					// execute query if not dry run
					} else {
						if ( ! $mysqli->query( $update_military_action ) ) {
							throw new \Exception(
								'Update query for military action failed! > ' . \EOL
									. $update_military_action . \EOL
									. $mysqli->error
							);
						}
					}
					// update config
					$new_handled_military_action_id = ( int )$row[ 'id' ];
				} catch ( \Exception $exception ) {
					echo \query_output(
						'failed',
						\EOL . 'Error while checking for active event: '
							. $exception->getMessage() . \EOL
							. (
								'cli' === \PHP_SAPI
									? $exception->getTraceAsString()
									: '<pre>' . $exception->getTraceAsString() . '</pre>'
							)
					);
					\flush();\ob_flush();\flush();
					// rollback and set autocommit back to true
					if( ! $dry_run ) {
						$mysqli->rollback();
						$mysqli->autocommit( true );
					}
					// close connection and continue
					$mysqli->close();
					continue 2;
				}

				if(
					$progress_count >= $progress_last
					|| $progress_count >= $action_result->num_rows
				) {
					$progress_last += $progress_limit;
					echo \EOL . $progress_count . '/' . $action_result->num_rows
						. ' (' . ( \microtime( true ) - $porgress_time ) . 's)';
					\flush(); \ob_flush(); \flush();
					if ( $progress_count >= $action_result->num_rows ) {
						if ( !$is_cli && !$dry_run ) {
							$continue_flag = true;
						}
						echo \EOL . \EOL;
						\flush(); \ob_flush(); \flush();
					}
				} else if (
					!$is_cli
					&& !$dry_run
					&& $progress_count % ( $progress_limit / 10 ) == 0
				) {
					echo '.';
					\flush(); \ob_flush(); \flush();
				}

				unset( $owner_score, $target_score );
			}
			$action_result->close();
			unset( $row, $action_result );
			// build config update query
			if( 0 < $new_handled_military_action_id ) {
				$update_config = '
					UPDATE
						`config`
					SET
						`value` = ' . ( int )$new_handled_military_action_id . '
					WHERE
						`name` = "CONFIG_LAST_PROCESSED_ACTION_ID"';
				// echo query during dry run
				if ( $dry_run ) {
					echo $update_config . \EOL;
					\flush();\ob_flush();\flush();
					// execute query if not dry run
				} else {
					if ( ! $mysqli->query( $update_config ) ) {
						throw new \Exception(
							'Update query for config failed!'
						);
					}
				}
			}
			// commit and set autocommit back to true
			if( ! $dry_run ) {
				$mysqli->commit();
				$mysqli->autocommit( true );
			}
			// close connection and flush
			$mysqli->close();
			\flush();\ob_flush();\flush();
		}
	}
}

if ( $continue_flag && 100 < $overall_count ) {
	echo \EOL
		. '<script type="text/javascript">'
		. 'if ( document.getElementById( \'continue\' ) && document.getElementById( \'continue\' ).checked ) {'
		. 	'setTimeout( function(){ window.location.reload(); }, 3000);'
		. 	'document.write( "To be continued... in 3s" );'
		. '} else {'
		. 	'document.write( "Done. Continuous processing cancelled." );'
		. '}'
		. '</script>';
} else if ( !$is_cli && !$dry_run && 100 > $overall_count ) {
	echo \EOL
		. '<script type="text/javascript">'
		. 	'document.write( "Done. Too little rows to process." );'
		. '</script>';
}
\flush();\ob_flush();\flush();
