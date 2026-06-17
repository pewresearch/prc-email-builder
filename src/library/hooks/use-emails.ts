import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

export interface EmailLibraryRow {
	id: number;
	type: 'campaign' | 'txn';
	title: string;
	status: string;
	date: string;
	modified: string;
	edit_url: string;
	newsletter_lists: Array<{ slug: string; label: string }>;
	subject: string;
	mailchimp_status: string;
	mandrill_status: string;
	delivery_mode: string;
}

interface PaginationInfo {
	totalItems: number;
	totalPages: number;
}

interface DataViewsView {
	type?: string;
	page?: number;
	perPage?: number;
	sort?: {
		field?: string;
		direction?: string;
	};
	search?: string;
	filters?: Array<{
		field?: string;
		value?: string | string[];
	}>;
}

function viewToQueryArgs(view: DataViewsView) {
	const args: Record<string, string | number> = {
		per_page: view.perPage || 20,
		page: view.page || 1,
		status: 'publish,draft,private',
	};

	if (view.search) {
		args.search = view.search;
	}

	if (view.sort?.field) {
		const fieldToOrderby: Record<string, string> = {
			title: 'title',
			date: 'date',
			modified: 'modified',
		};
		args.orderby = fieldToOrderby[view.sort.field] || 'date';
		args.order = view.sort.direction || 'desc';
	} else {
		args.orderby = 'date';
		args.order = 'desc';
	}

	if (view.filters?.length) {
		view.filters.forEach((filter) => {
			const values = Array.isArray(filter.value)
				? filter.value
				: [filter.value];
			const joined = values.filter(Boolean).join(',');
			if (!joined) {
				return;
			}

			if (filter.field === 'type') {
				const typeValues = values.filter(Boolean);
				if (
					typeValues.length >= 2 ||
					(typeValues.includes('campaign') &&
						typeValues.includes('txn'))
				) {
					args.post_type = 'all';
				} else if (typeValues.length === 1) {
					args.post_type = typeValues[0];
				}
			}
			if (filter.field === 'newsletterLists') {
				args.newsletter_list = joined;
			}
			if (filter.field === 'sendStatus') {
				const mailchimpValues: string[] = [];
				const mandrillValues: string[] = [];

				values.filter(Boolean).forEach((value) => {
					const [typePrefix, ...rest] = String(value).split(':');
					const statusValue = rest.join(':');
					if (!statusValue) {
						return;
					}
					if (typePrefix === 'campaign') {
						mailchimpValues.push(statusValue);
					}
					if (typePrefix === 'txn') {
						mandrillValues.push(statusValue);
					}
				});

				if (mailchimpValues.length) {
					args.mailchimp_status = mailchimpValues.join(',');
				}
				if (mandrillValues.length) {
					args.mandrill_status = mandrillValues.join(',');
				}
			}
			if (filter.field === 'status') {
				args.status = joined;
			}
		});
	}

	return args;
}

export const useEmails = (view: DataViewsView, externalRefreshToken = 0) => {
	const [emails, setEmails] = useState<EmailLibraryRow[]>([]);
	const [isLoading, setIsLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);
	const [paginationInfo, setPaginationInfo] = useState<PaginationInfo>({
		totalItems: 0,
		totalPages: 1,
	});

	const [refreshToken, setRefreshToken] = useState(0);
	const refresh = useCallback(() => setRefreshToken((n) => n + 1), []);

	const abortRef = useRef<AbortController | null>(null);

	useEffect(() => {
		if (abortRef.current) {
			abortRef.current.abort();
		}
		const controller = new AbortController();
		abortRef.current = controller;

		setIsLoading(true);
		setError(null);

		const queryArgs = viewToQueryArgs(view);
		const path = addQueryArgs('/prc-email-builder/v1/library', queryArgs);

		apiFetch({ path, signal: controller.signal, parse: false })
			.then(async (response) => {
				const total = parseInt(
					response.headers.get('X-WP-Total') || '0',
					10
				);
				const totalPages = parseInt(
					response.headers.get('X-WP-TotalPages') || '1',
					10
				);
				const data = (await response.json()) as EmailLibraryRow[];
				setEmails(data);
				setPaginationInfo({ totalItems: total, totalPages });
			})
			.catch((err: Error) => {
				if (err.name !== 'AbortError') {
					setError(err.message || 'Failed to load emails.');
					setEmails([]);
				}
			})
			.finally(() => {
				if (!controller.signal.aborted) {
					setIsLoading(false);
				}
			});

		return () => controller.abort();
	}, [view, refreshToken, externalRefreshToken]);

	return { emails, paginationInfo, isLoading, error, refresh };
};

export default useEmails;
