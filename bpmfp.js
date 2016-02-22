(function($){
	var groups;

	$(document).ready( function() {
		$groups = $( '#crosspost-div' ).find( '#crosspost-groups li' );
		$showmore = $( '#crosspost-show-more' );
		$groups.each( function( k, v ) {
			if ( k >= 5 ) {
				$(v).hide();
				$showmore.show();
			}
		} );

		$showmore.on( 'click', function() {
			$groups.each( function() {
				$(this).show();
			} );

			$showmore.hide();

			return false;
		} );
	} );
}(jQuery));
