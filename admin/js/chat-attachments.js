/**
 * Shared chat image upload and screenshot helpers.
 *
 * @package Abilities_Bridge
 */

(function(window, $) {
	'use strict';

	const defaults = {
		attachments_enabled: true,
		max_images: 3,
		max_bytes: 4194304,
		max_long_edge: 1568,
		allowed_mimes: ['image/jpeg', 'image/png', 'image/webp'],
		accept: 'image/jpeg,image/png,image/webp',
		max_bytes_label: '4 MB'
	};

	function create(options) {
		const settings = $.extend(true, {}, defaults, options.config || {});
		const selectors = options.selectors || {};
		const onStatus = typeof options.onStatus === 'function' ? options.onStatus : function() {};
		const state = {
			items: []
		};

		const $root = $(selectors.root);
		const $fileInput = $(selectors.fileInput);
		const $uploadButton = $(selectors.uploadButton);
		const $screenshotButton = $(selectors.screenshotButton);
		const $preview = $(selectors.preview);
		const $status = $(selectors.status);

		function init() {
			if (!$root.length) {
				return;
			}

			if (settings.attachments_enabled === false) {
				$root.prop('hidden', true).hide();
				$fileInput.prop('disabled', true);
				return;
			}

			$fileInput.attr('accept', settings.accept);
			$uploadButton.on('click', function() {
				$fileInput.trigger('click');
			});
			$fileInput.on('change', handleFiles);
			$screenshotButton.on('click', captureScreenshot);
			$preview.on('click', '[data-remove-attachment]', function() {
				removeItem($(this).data('remove-attachment'));
			});

			if (!supportsScreenCapture()) {
				$screenshotButton.prop('disabled', true).attr('title', 'Screenshot capture is unavailable in this browser.');
			}

			render();
		}

		function getPayload() {
			if (settings.attachments_enabled === false) {
				return [];
			}

			return state.items.map(function(item) {
				return {
					data_url: item.dataUrl,
					mime_type: item.mimeType,
					width: item.width,
					height: item.height,
					size: item.size,
					source: item.source,
					name: item.name
				};
			});
		}

		function clear() {
			state.items.forEach(function(item) {
				if (item.previewUrl) {
					window.URL.revokeObjectURL(item.previewUrl);
				}
			});
			state.items = [];
			$fileInput.val('');
			render();
		}

		function setDisabled(disabled) {
			if (settings.attachments_enabled === false) {
				return;
			}

			$uploadButton.prop('disabled', disabled);
			$screenshotButton.prop('disabled', disabled || !supportsScreenCapture());
			$fileInput.prop('disabled', disabled);
			$preview.find('[data-remove-attachment]').prop('disabled', disabled);
		}

		function handleFiles(event) {
			const files = Array.prototype.slice.call(event.target.files || []);
			$fileInput.val('');

			files.reduce(function(promise, file) {
				return promise.then(function() {
					return addFile(file, 'upload');
				});
			}, Promise.resolve()).catch(showError);
		}

		function addFile(file, source) {
			if (state.items.length >= settings.max_images) {
				return Promise.reject(limitMessage());
			}

			if (!settings.allowed_mimes.includes(file.type)) {
				return Promise.reject('Only JPEG, PNG, and WebP images are supported.');
			}

			return normalizeFile(file, source).then(function(item) {
				state.items.push(item);
				render();
				showStatus(source === 'screenshot' ? 'Screenshot attached.' : 'Image attached.');
			});
		}

		function captureScreenshot() {
			if (!supportsScreenCapture()) {
				showError('Screenshot capture is unavailable in this browser. You can upload an image instead.');
				return;
			}

			if (state.items.length >= settings.max_images) {
				showError(limitMessage());
				return;
			}

			showStatus('Choose a screen, window, or tab to capture.');

			window.navigator.mediaDevices.getDisplayMedia({ video: true, audio: false })
				.then(function(stream) {
					return captureFrame(stream);
				})
				.then(function(blob) {
					const file = new File([blob], 'screenshot.png', { type: blob.type || 'image/png' });
					return addFile(file, 'screenshot');
				})
				.catch(function(error) {
					if (error && (error.name === 'NotAllowedError' || error.name === 'AbortError')) {
						showStatus('Screenshot capture was cancelled.');
					} else {
						showError('Unable to capture a screenshot. You can upload an image instead.');
					}
				});
		}

		function captureFrame(stream) {
			return new Promise(function(resolve, reject) {
				const video = document.createElement('video');
				video.muted = true;
				video.srcObject = stream;
				video.onloadedmetadata = function() {
					video.play().then(function() {
						window.setTimeout(function() {
							try {
								const canvas = document.createElement('canvas');
								const size = fitDimensions(video.videoWidth, video.videoHeight);
								canvas.width = size.width;
								canvas.height = size.height;
								canvas.getContext('2d').drawImage(video, 0, 0, size.width, size.height);
								stopStream(stream);
								canvasToSizedBlob(canvas, 'image/png').then(resolve).catch(reject);
							} catch (error) {
								stopStream(stream);
								reject(error);
							}
						}, 150);
					}).catch(function(error) {
						stopStream(stream);
						reject(error);
					});
				};
				video.onerror = function() {
					stopStream(stream);
					reject(new Error('Video capture failed.'));
				};
			});
		}

		function normalizeFile(file, source) {
			return loadImage(file).then(function(image) {
				const canvas = document.createElement('canvas');
				const size = fitDimensions(image.width, image.height);
				canvas.width = size.width;
				canvas.height = size.height;
				canvas.getContext('2d').drawImage(image, 0, 0, size.width, size.height);

				return canvasToSizedBlob(canvas, file.type).then(function(blob) {
					if (blob.size > settings.max_bytes) {
						throw new Error('Image is too large after normalization. Please choose a smaller image.');
					}

					return blobToDataUrl(blob).then(function(dataUrl) {
						return {
							id: randomId(),
							dataUrl: dataUrl,
							previewUrl: window.URL.createObjectURL(blob),
							mimeType: blob.type || file.type,
							width: size.width,
							height: size.height,
							size: blob.size,
							source: source,
							name: source === 'screenshot' ? 'screenshot.' + extensionForMime(blob.type || file.type) : file.name
						};
					});
				});
			});
		}

		function canvasToSizedBlob(canvas, preferredMime) {
			const mime = settings.allowed_mimes.includes(preferredMime) ? preferredMime : 'image/jpeg';

			return canvasToBlob(canvas, mime, 0.88).then(function(blob) {
				if (blob.size <= settings.max_bytes) {
					return blob;
				}

				return tryJpegQualities(canvas, [0.82, 0.72, 0.62]);
			});
		}

		function tryJpegQualities(canvas, qualities) {
			let chain = Promise.reject();
			qualities.forEach(function(quality) {
				chain = chain.catch(function() {
					return canvasToBlob(canvas, 'image/jpeg', quality).then(function(blob) {
						if (blob.size <= settings.max_bytes) {
							return blob;
						}
						throw new Error('Image remains too large.');
					});
				});
			});
			return chain;
		}

		function canvasToBlob(canvas, mime, quality) {
			return new Promise(function(resolve, reject) {
				canvas.toBlob(function(blob) {
					if (!blob) {
						reject(new Error('Unable to process image.'));
						return;
					}
					resolve(blob);
				}, mime, quality);
			});
		}

		function loadImage(file) {
			return new Promise(function(resolve, reject) {
				const image = new Image();
				const url = window.URL.createObjectURL(file);
				image.onload = function() {
					window.URL.revokeObjectURL(url);
					resolve(image);
				};
				image.onerror = function() {
					window.URL.revokeObjectURL(url);
					reject(new Error('Unable to read image.'));
				};
				image.src = url;
			});
		}

		function blobToDataUrl(blob) {
			return new Promise(function(resolve, reject) {
				const reader = new FileReader();
				reader.onload = function() {
					resolve(reader.result);
				};
				reader.onerror = function() {
					reject(new Error('Unable to read image.'));
				};
				reader.readAsDataURL(blob);
			});
		}

		function fitDimensions(width, height) {
			const longEdge = Math.max(width, height);
			if (longEdge <= settings.max_long_edge) {
				return { width: width, height: height };
			}

			const scale = settings.max_long_edge / longEdge;
			return {
				width: Math.max(1, Math.round(width * scale)),
				height: Math.max(1, Math.round(height * scale))
			};
		}

		function render() {
			$preview.empty();

			if (!state.items.length) {
				$root.removeClass('has-attachments');
				$status.text('');
				return;
			}

			$root.addClass('has-attachments');
			state.items.forEach(function(item) {
				$preview.append(`
					<div class="abilities-bridge-attachment-preview">
						<img src="${escapeAttribute(item.previewUrl)}" alt="${escapeAttribute(item.source === 'screenshot' ? 'Screenshot preview' : 'Image preview')}">
						<div class="abilities-bridge-attachment-meta">
							<span>${escapeHtml(item.source === 'screenshot' ? 'Screenshot' : item.name)}</span>
							<span>${item.width}x${item.height}</span>
						</div>
						<button type="button" data-remove-attachment="${escapeAttribute(item.id)}" aria-label="Remove image">×</button>
					</div>
				`);
			});
		}

		function removeItem(id) {
			state.items = state.items.filter(function(item) {
				if (item.id === id) {
					if (item.previewUrl) {
						window.URL.revokeObjectURL(item.previewUrl);
					}
					return false;
				}
				return true;
			});
			render();
		}

		function showError(message) {
			const text = message && message.message ? message.message : String(message || 'Unable to attach image.');
			showStatus(text);
		}

		function showStatus(message) {
			$status.text(message || '');
			onStatus(message || '');
		}

		function supportsScreenCapture() {
			return !!(window.navigator.mediaDevices && window.navigator.mediaDevices.getDisplayMedia);
		}

		function stopStream(stream) {
			if (!stream) {
				return;
			}
			stream.getTracks().forEach(function(track) {
				track.stop();
			});
		}

		function limitMessage() {
			return 'You can attach up to ' + settings.max_images + ' images per message.';
		}

		init();

		return {
			getPayload: getPayload,
			clear: clear,
			setDisabled: setDisabled,
			hasItems: function() {
				return state.items.length > 0;
			}
		};
	}

	function renderMessageContent(content) {
		const blocks = normalizeDisplayBlocks(content);
		let html = '';

		const images = blocks.filter(function(block) {
			return block.type === 'image';
		});
		const text = blocks.filter(function(block) {
			return block.type === 'text';
		}).map(function(block) {
			return block.text;
		}).filter(Boolean).join('\n\n');

		if (images.length) {
			html += '<div class="abilities-bridge-message-attachments">';
			images.forEach(function(image) {
				const label = image.source === 'screenshot' ? 'Screenshot' : (image.name || 'Image attachment');
				html += `
					<a class="abilities-bridge-message-attachment" href="${escapeAttribute(image.preview_url || image.previewUrl || '')}" target="_blank" rel="noopener">
						<img src="${escapeAttribute(image.preview_url || image.previewUrl || '')}" alt="${escapeAttribute(label)}">
						<span>${escapeHtml(label)}</span>
					</a>
				`;
			});
			html += '</div>';
		}

		if (text) {
			html += '<div class="abilities-bridge-message-text">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>';
		}

		return html || '<div class="abilities-bridge-message-text"></div>';
	}

	function extractDisplayText(content) {
		const blocks = normalizeDisplayBlocks(content);
		return blocks.filter(function(block) {
			return block.type === 'text';
		}).map(function(block) {
			return block.text;
		}).filter(Boolean).join('\n\n');
	}

	function normalizeDisplayBlocks(content) {
		if (Array.isArray(content)) {
			return content.map(function(block) {
				if (block && block.type === 'text') {
					return { type: 'text', text: block.text || '' };
				}
				if (block && block.type === 'image') {
					return block;
				}
				return null;
			}).filter(Boolean);
		}

		if (content && typeof content === 'object') {
			if (Array.isArray(content.attachments) || content.text) {
				const blocks = [];
				(content.attachments || []).forEach(function(item) {
					blocks.push($.extend({ type: 'image' }, item));
				});
				if (content.text) {
					blocks.push({ type: 'text', text: content.text });
				}
				return blocks;
			}
			return [{ type: 'text', text: JSON.stringify(content, null, 2) }];
		}

		return [{ type: 'text', text: String(content || '') }];
	}

	function escapeHtml(text) {
		const map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return String(text || '').replace(/[&<>"']/g, function(match) {
			return map[match];
		});
	}

	function escapeAttribute(text) {
		return escapeHtml(text).replace(/`/g, '&#096;');
	}

	function randomId() {
		if (window.crypto && window.crypto.getRandomValues) {
			const bytes = new Uint8Array(8);
			window.crypto.getRandomValues(bytes);
			return Array.prototype.map.call(bytes, function(byte) {
				return byte.toString(16).padStart(2, '0');
			}).join('');
		}

		return String(Date.now()) + String(Math.random()).slice(2);
	}

	function extensionForMime(mime) {
		if (mime === 'image/png') {
			return 'png';
		}
		if (mime === 'image/webp') {
			return 'webp';
		}
		return 'jpg';
	}

	window.AbilitiesBridgeChatAttachments = {
		create: create,
		renderMessageContent: renderMessageContent,
		extractDisplayText: extractDisplayText,
		escapeHtml: escapeHtml
	};
})(window, jQuery);
