/** The explorer's only link to the server: a tiny fetch wrapper (JSON) plus XHR for chunks, so upload progress works. */

export class ApiError extends Error {
	constructor( message, status, data, code ) {
		super( message );
		this.status = status;
		this.data = data || {};
		this.code = code || '';
	}
}

export function createApi( boot ) {
	const base = String( boot.api || '' );

	const url = ( path, params ) => {
		const p = new URLSearchParams();
		Object.entries( params || {} ).forEach( ( [ k, v ] ) => {
			if ( v !== undefined && v !== null && v !== '' && v !== false ) p.append( k, v === true ? 1 : v );
		} );
		const q = p.toString();
		if ( base.includes( '?' ) ) return base + path + ( q ? '&' + q : '' ); // ?rest_route=/… style addresses
		return base.replace( /\/$/, '' ) + path + ( q ? '?' + q : '' );
	};

	const headers = ( extra ) => {
		const h = { 'X-WP-Nonce': boot.nonce || '', Accept: 'application/json', ...( extra || {} ) };
		if ( boot.as ) h[ 'X-WPFlat-As' ] = String( boot.as );
		return h;
	};

	const fail = ( status, body ) => {
		const data = body && typeof body.data === 'object' && body.data ? body.data : {};
		let msg = ( body && body.message ) || '';
		if ( ! msg ) msg = status === 403 ? 'You do not have permission to do that.' : status === 404 ? 'That was not found.' : status === 413 ? 'That file is too large for the server.' : 'Something went wrong (' + status + ').';
		return new ApiError( msg, status, data, body && body.code );
	};

	async function request( method, path, { params, data } = {} ) {
		let res;
		try {
			res = await fetch( url( path, params ), {
				method,
				credentials: 'same-origin',
				headers: headers( data === undefined ? {} : { 'Content-Type': 'application/json' } ),
				body: data === undefined ? undefined : JSON.stringify( data ),
			} );
		} catch ( e ) {
			throw new ApiError( 'You seem to be offline. Check your connection and try again.', 0 );
		}
		let body = null;
		try {
			body = await res.json();
		} catch ( e ) {
			body = null;
		}
		if ( ! res.ok ) throw fail( res.status, body );
		return body;
	}

	/** One chunk of an upload. onProgress( loadedBytes ); onXhr( xhr ) so the caller can abort. */
	function chunk( uploadId, index, blob, filename, onProgress, onXhr ) {
		return new Promise( ( resolve, reject ) => {
			const form = new FormData();
			form.append( 'upload_id', uploadId );
			form.append( 'index', String( index ) );
			form.append( 'chunk', blob, filename || 'chunk' );
			const x = new XMLHttpRequest();
			if ( onXhr ) onXhr( x );
			x.open( 'POST', url( '/fm/upload/chunk' ) );
			Object.entries( headers() ).forEach( ( [ k, v ] ) => x.setRequestHeader( k, v ) );
			x.withCredentials = true;
			if ( x.upload ) {
				x.upload.onprogress = ( e ) => {
					if ( onProgress ) onProgress( e.loaded );
				};
			}
			x.onload = () => {
				let body = null;
				try {
					body = JSON.parse( x.responseText );
				} catch ( e ) {
					body = null;
				}
				if ( x.status >= 200 && x.status < 300 ) resolve( body );
				else reject( fail( x.status, body ) );
			};
			x.onerror = () => reject( new ApiError( 'The connection dropped while uploading.', 0 ) );
			x.onabort = () => reject( new ApiError( 'Cancelled', -1 ) );
			x.send( form );
		} );
	}

	return {
		url,
		get: ( path, params ) => request( 'GET', path, { params } ),
		post: ( path, data ) => request( 'POST', path, { data: data || {} } ),
		put: ( path, data ) => request( 'PUT', path, { data: data || {} } ),
		patch: ( path, data ) => request( 'PATCH', path, { data: data || {} } ),
		del: ( path ) => request( 'DELETE', path ),
		chunk,
	};
}
