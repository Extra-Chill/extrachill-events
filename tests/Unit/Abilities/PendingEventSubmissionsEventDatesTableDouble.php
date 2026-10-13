<?php

namespace DataMachineEvents\Core {
	class EventDatesTable {
		public static function get( int $post_id ) {
			return $GLOBALS['pending_digest_event_dates'][ $post_id ] ?? null;
		}
	}
}
