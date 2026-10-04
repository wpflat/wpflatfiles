/** Drag and drop: files from the desktop (upload) and items inside the explorer (move, or copy with Ctrl). */
export const TYPE = 'application/x-bizyfiles';
const types = ( e ) => [ ...( ( e.dataTransfer && e.dataTransfer.types ) || [] ) ];
export const isFileDrag = ( e ) => types( e ).includes( 'Files' );
export const isInternal = ( e ) => types( e ).includes( TYPE );

/** Handlers that make an element (a folder row, a tree node, a breadcrumb) accept drops into folder `id`. */
export const dropTarget = ( fx, id ) => ( {
	onDragover( e ) {
		const internal = isInternal( e );
		const ok = internal ? fx.canDropOn( id ) : isFileDrag( e ) && fx.levelOf( id ) >= 2;
		if ( ! ok ) return;
		e.preventDefault();
		e.stopPropagation();
		e.dataTransfer.dropEffect = internal && ( e.ctrlKey || e.altKey ) ? 'copy' : internal ? 'move' : 'copy';
		fx.st.dropOn = id;
	},
	onDragleave( e ) {
		if ( ! e.currentTarget.contains( e.relatedTarget ) && fx.st.dropOn === id ) fx.st.dropOn = null;
	},
	onDrop( e ) {
		const internal = isInternal( e );
		if ( ! internal && ! isFileDrag( e ) ) return;
		e.preventDefault();
		e.stopPropagation();
		fx.st.dropOn = null;
		if ( internal ) {
			const list = fx.st.dragKeys.map( ( k ) => fx.items.find( ( i ) => i.key === k ) ).filter( Boolean );
			fx.st.dragKeys = [];
			if ( ! list.length ) return;
			if ( e.ctrlKey || e.altKey ) fx.copyItems( list, id );
			else fx.moveItems( list, id );
		} else fx.addDropped( e.dataTransfer, id );
	},
} );
