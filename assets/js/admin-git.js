/**
 * تعامل صفحهٔ «آپدیت از گیت».
 *
 * بدون وابستگی خارجی؛ همهٔ متن‌ها با textContent درج می‌شوند.
 *
 * @package Falnic\SecureHLS
 */

( function () {
	'use strict';

	if ( 'undefined' === typeof window.FalnicGit ) {
		return;
	}

	var cfg = window.FalnicGit;
	var i18n = cfg.i18n || {};

	var els = {
		check: document.getElementById( 'falnic-git-check' ),
		update: document.getElementById( 'falnic-git-update' ),
		spinner: document.getElementById( 'falnic-git-spinner' ),
		status: document.getElementById( 'falnic-git-status' ),
		info: document.getElementById( 'falnic-git-info' ),
		report: document.getElementById( 'falnic-git-report' ),
		reportList: document.getElementById( 'falnic-git-report-list' ),
		reportFiles: document.getElementById( 'falnic-git-report-files' ),
		backupStatus: document.getElementById( 'falnic-git-backups-status' )
	};

	/**
	 * نمایش وضعیت.
	 *
	 * @param {string} message متن.
	 * @param {string} kind    ok|error|busy.
	 * @param {Object} target  عنصر مقصد (اختیاری).
	 */
	function setStatus( message, kind, target ) {
		var box = target || els.status;
		if ( ! box ) {
			return;
		}

		box.className = 'falnic-status is-' + ( kind || 'busy' );
		box.textContent = message || '';
	}

	/**
	 * فعال/غیرفعال کردن کنترل‌ها.
	 *
	 * @param {boolean} busy وضعیت.
	 */
	function setBusy( busy ) {
		if ( els.spinner ) {
			els.spinner.classList.toggle( 'is-active', !! busy );
		}

		[ els.check, els.update ].forEach( function ( button ) {
			if ( button ) {
				button.disabled = !! busy;
			}
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '.falnic-rollback, .falnic-delete' ), function ( button ) {
			button.disabled = !! busy;
		} );
	}

	/**
	 * درخواست AJAX به admin-ajax.php.
	 *
	 * @param {string} action نام اکشن.
	 * @param {Object} data   داده‌های اضافی.
	 * @return {Promise<Object>}
	 */
	function request( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( i18n.networkError );
			} );
		} );
	}

	/**
	 * درج متن در عنصر بر اساس data-field.
	 *
	 * @param {HTMLElement} root ریشه.
	 * @param {Object}      data داده.
	 */
	function fillFields( root, data ) {
		Array.prototype.forEach.call( root.querySelectorAll( '[data-field]' ), function ( node ) {
			var key = node.getAttribute( 'data-field' );
			node.textContent = data && undefined !== data[ key ] ? String( data[ key ] ) : '—';
		} );
	}

	/**
	 * نمایش لیست فایل‌های گزارش.
	 *
	 * @param {Object} report گزارش.
	 */
	function renderReport( report ) {
		if ( ! els.report ) {
			return;
		}

		els.report.hidden = false;

		if ( els.reportList ) {
			els.reportList.textContent = '';
		}

		if ( els.reportFiles ) {
			els.reportFiles.textContent = '';
		}

		var added = report.added || [];
		var changed = report.changed || [];
		var removed = report.removed || [];

		var summary = [
			( i18n.labelBranch || 'شاخه' ) + ': ' + ( report.branch || '—' ),
			( i18n.labelCommit || 'کامیت' ) + ': ' + ( report.short_sha || '—' ),
			( i18n.labelVersion || 'نسخه' ) + ': ' + ( report.previous_version || '—' ) + ' → ' + ( report.new_version || '—' ),
			( i18n.labelBackup || 'پشتیبان' ) + ': ' + ( report.backup || '—' ),
			( i18n.labelAdded || 'افزوده‌شده' ) + ': ' + added.length,
			( i18n.labelChanged || 'تغییرکرده' ) + ': ' + changed.length,
			( i18n.labelRemoved || 'حذف‌شده' ) + ': ' + removed.length
		];

		summary.forEach( function ( line ) {
			var item = document.createElement( 'li' );
			item.textContent = line;

			if ( els.reportList ) {
				els.reportList.appendChild( item );
			}
		} );

		if ( ! els.reportFiles ) {
			return;
		}

		[
			[ 'افزوده‌شده', added ],
			[ 'تغییرکرده', changed ],
			[ 'حذف‌شده', removed ]
		].forEach( function ( group ) {
			if ( ! group[ 1 ] || ! group[ 1 ].length ) {
				return;
			}

			var title = document.createElement( 'h4' );
			title.textContent = group[ 0 ] + ' (' + group[ 1 ].length + ')';
			els.reportFiles.appendChild( title );

			var box = document.createElement( 'div' );
			box.className = 'falnic-files';
			group[ 1 ].forEach( function ( file ) {
				var line = document.createElement( 'span' );
				line.textContent = file;
				box.appendChild( line );
			} );
			els.reportFiles.appendChild( box );
		} );

		if ( report.warnings && report.warnings.length ) {
			var warn = document.createElement( 'div' );
			warn.className = 'falnic-warn';
			warn.textContent = report.warnings.join( '\n' );
			els.reportFiles.appendChild( warn );
		}
	}

	/**
	 * بررسی نسخه.
	 */
	function check() {
		setBusy( true );
		setStatus( i18n.stepCheck || '', 'busy' );

		request( 'falnic_git_check', {} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					var message = response && response.data && response.data.message ? response.data.message : i18n.networkError;
					setStatus( message, 'error' );
					return;
				}

				var info = response.data.info || {};
				if ( els.info ) {
					els.info.hidden = false;
					fillFields( els.info, info );
				}

				setStatus( response.data.message || '', 'ok' );
			} )
			.catch( function ( error ) {
				setStatus( error.message || i18n.networkError, 'error' );
			} )
			.finally( function () {
				setBusy( false );
			} );
	}

	/**
	 * اجرای آپدیت.
	 */
	function update() {
		if ( ! window.confirm( i18n.confirmUpdate || '' ) ) {
			return;
		}

		setBusy( true );
		setStatus( i18n.stepDownload || '', 'busy' );

		request( 'falnic_git_update', {} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					var message = response && response.data && response.data.message ? response.data.message : i18n.networkError;
					var extra = '';

					if ( response && response.data && response.data.backup_available ) {
						extra = '\n' + ( i18n.backupAvailable || 'پشتیبان موجود' ) + ': ' + response.data.backup + ' — ' + ( i18n.rollbackHint || 'از کارت پشتیبان‌ها بازگردانی کنید.' );
					}

					setStatus( message + extra, 'error' );
					return;
				}

				setStatus( response.data.message || '', 'ok' );
				renderReport( response.data.report || {} );
				setTimeout( function () {
					window.location.reload();
				}, 2500 );
			} )
			.catch( function ( error ) {
				setStatus( error.message || i18n.networkError, 'error' );
			} )
			.finally( function () {
				setBusy( false );
			} );
	}

	/**
	 * بازگردانی.
	 *
	 * @param {string} backup نام فایل.
	 */
	function rollback( backup ) {
		if ( ! window.confirm( i18n.confirmRollback || '' ) ) {
			return;
		}

		setBusy( true );
		setStatus( i18n.working || '', 'busy', els.backupStatus );

		request( 'falnic_git_rollback', { backup: backup } )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					setStatus( response && response.data ? response.data.message : i18n.networkError, 'error', els.backupStatus );
					return;
				}

				setStatus( response.data.message + ' — صفحه دوباره بارگذاری می‌شود…', 'ok', els.backupStatus );
				setTimeout( function () {
					window.location.reload();
				}, 2000 );
			} )
			.catch( function ( error ) {
				setStatus( error.message || i18n.networkError, 'error', els.backupStatus );
			} )
			.finally( function () {
				setBusy( false );
			} );
	}

	/**
	 * حذف پشتیبان.
	 *
	 * @param {string} backup نام فایل.
	 * @param {HTMLElement} button دکمه.
	 */
	function deleteBackup( backup, button ) {
		if ( ! window.confirm( i18n.confirmDelete || '' ) ) {
			return;
		}

		setBusy( true );
		setStatus( i18n.working || '', 'busy', els.backupStatus );

		request( 'falnic_git_delete_backup', { backup: backup } )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					setStatus( response && response.data ? response.data.message : i18n.networkError, 'error', els.backupStatus );
					return;
				}

				setStatus( response.data.message || '', 'ok', els.backupStatus );

				var row = button ? button.closest( 'tr' ) : null;
				if ( row && row.parentNode ) {
					row.parentNode.removeChild( row );
				}
			} )
			.catch( function ( error ) {
				setStatus( error.message || i18n.networkError, 'error', els.backupStatus );
			} )
			.finally( function () {
				setBusy( false );
			} );
	}

	if ( els.check ) {
		els.check.addEventListener( 'click', check );
	}

	if ( els.update ) {
		els.update.addEventListener( 'click', update );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.classList ) {
			return;
		}

		if ( target.classList.contains( 'falnic-rollback' ) ) {
			rollback( target.getAttribute( 'data-backup' ) || '' );
		}

		if ( target.classList.contains( 'falnic-delete' ) ) {
			deleteBackup( target.getAttribute( 'data-backup' ) || '', target );
		}
	} );
}() );
