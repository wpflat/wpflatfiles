import { reactive } from 'vue';
import { extOf, fmtSize } from './util';

const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/**
 * The upload manager: a queue that runs up to two files at once. Each file goes init → chunks (one after the other, each
 * retried up to 3 times, a 409 resumes at data.next) → finish. Folder uploads create the folders first.
 */
export function createUploader( { api, cfg, onDone, onFolders, retryDelay = 600, parallel = 2 } ) {
	const list = reactive( [] );
	let seq = 0;
	let running = 0;

	const problem = ( file ) => {
		const ext = extOf( file.name );
		const allowed = ( cfg.allowed || [] ).map( ( e ) => String( e ).toLowerCase().replace( /^\./, '' ) );
		if ( allowed.length && ! allowed.includes( ext ) ) {
			return ext ? 'Files of type .' + ext + ' are not allowed here.' : 'Files without an extension are not allowed here.';
		}
		const max = Number( cfg.max_mb ) || 0;
		if ( max && file.size > max * 1048576 ) return 'Too large: the limit is ' + max + ' MB and this file is ' + fmtSize( file.size ) + '.';
		return '';
	};

	/** entries: [ { file, folder_id, file_id?, path? } ] */
	const enqueue = ( entries ) => {
		const added = [];
		entries.forEach( ( e ) => {
			const t = reactive( {
				id: ++seq,
				name: e.file.name,
				size: e.file.size,
				folder_id: e.folder_id,
				file_id: e.file_id || 0,
				path: e.path || '',
				status: 'queued',
				progress: 0,
				error: '',
				file: e.file,
				upload_id: '',
				cancelled: false,
			} );
			const bad = problem( e.file );
			if ( bad ) {
				t.status = 'error';
				t.error = bad;
			}
			list.push( t );
			added.push( t );
		} );
		pump();
		return added;
	};

	const pump = () => {
		while ( running < parallel ) {
			const t = list.find( ( x ) => x.status === 'queued' );
			if ( ! t ) return;
			running++;
			t.status = 'uploading';
			run( t ).finally( () => {
				running--;
				pump();
			} );
		}
	};

	async function run( t ) {
		try {
			const init = await api.post( '/fm/upload/init', {
				folder_id: t.folder_id,
				name: t.name,
				size: t.size,
				mime: t.file.type || '',
				...( t.file_id ? { file_id: t.file_id } : {} ),
			} );
			if ( t.cancelled ) return abort( t, init.upload_id );
			t.upload_id = init.upload_id;
			const cs = Number( init.chunk_size ) || Number( cfg.chunk_size ) || 4194304;
			const total = Math.max( 1, Math.ceil( t.size / cs ) );
			let i = 0;
			let resumed = 0;
			while ( i < total ) {
				if ( t.cancelled ) return;
				const blob = t.file.slice( i * cs, Math.min( t.size, ( i + 1 ) * cs ) );
				let attempt = 0;
				for ( ;; ) {
					try {
						await api.chunk( t.upload_id, i, blob, t.name, ( loaded ) => {
							t.progress = t.size ? Math.min( 0.99, ( i * cs + loaded ) / t.size ) : 0;
						}, ( x ) => {
							t.xhr = x;
						} );
						i++;
						t.progress = t.size ? Math.min( 0.99, ( i * cs ) / t.size ) : 0;
						break;
					} catch ( e ) {
						if ( t.cancelled ) return;
						if ( e.status === 409 && Number.isInteger( e.data && e.data.next ) && resumed++ < 6 ) {
							i = e.data.next; // the server says which piece it wants next
							break;
						}
						if ( e.status >= 400 && e.status < 500 && e.status !== 409 && e.status !== 408 && e.status !== 429 ) throw e; // not worth retrying
						if ( ++attempt >= 3 ) throw e;
						await sleep( retryDelay * attempt );
					}
				}
			}
			if ( t.cancelled ) return;
			const row = await api.post( '/fm/upload/finish', { upload_id: t.upload_id } );
			t.status = 'done';
			t.progress = 1;
			onDone && onDone( t, row );
		} catch ( e ) {
			if ( t.cancelled ) return;
			t.status = 'error';
			t.error = e.message || 'The upload failed.';
		} finally {
			t.xhr = null;
		}
	}

	const abort = async ( t, id ) => {
		try {
			if ( id ) await api.del( '/fm/upload/' + id );
		} catch ( e ) { /* the server sweeps stale uploads */ }
	};

	const cancel = ( t ) => {
		if ( t.status === 'done' || t.status === 'cancelled' ) return;
		const was = t.status;
		t.cancelled = true;
		t.status = 'cancelled';
		if ( t.xhr ) {
			try {
				t.xhr.abort();
			} catch ( e ) { /* already finished */ }
		}
		if ( was === 'uploading' && t.upload_id ) abort( t, t.upload_id );
	};

	const retry = ( t ) => {
		if ( t.status !== 'error' && t.status !== 'cancelled' ) return;
		const bad = problem( t.file );
		if ( bad ) {
			t.error = bad;
			return;
		}
		t.cancelled = false;
		t.error = '';
		t.progress = 0;
		t.upload_id = '';
		t.status = 'queued';
		pump();
	};

	const clearFinished = () => {
		for ( let i = list.length - 1; i >= 0; i-- ) {
			if ( [ 'done', 'cancelled', 'error' ].includes( list[ i ].status ) ) list.splice( i, 1 );
		}
	};

	return { list, enqueue, cancel, retry, clearFinished, problem };
}
