import { DataViews as DataViewsComponent } from '@wordpress/dataviews';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import actions from '../actions';
import fields from '../fields';
import useEmails from '../hooks/use-emails';

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: {
		field: 'date',
		direction: 'desc',
	},
	search: '',
	filters: [],
	titleField: 'title',
	fields: ['type', 'newsletterLists', 'sendStatus', 'status', 'date'],
	layout: {
		primaryField: 'title',
	},
};

const DEFAULT_LAYOUTS = {
	table: {
		layout: {
			primaryField: 'title',
		},
	},
};

interface DataViewsProps {
	refreshToken?: number;
}

export default function DataViews({ refreshToken = 0 }: DataViewsProps) {
	const [view, setView] = useState(DEFAULT_VIEW);
	const { emails, paginationInfo, isLoading, error, refresh } = useEmails(
		view,
		refreshToken
	);

	const actionsWithRefresh = useMemo(
		() =>
			actions.map((action) => {
				if (action.id !== 'trash-email') {
					return action;
				}
				return {
					...action,
					RenderModal: (props) => {
						const OriginalModal = action.RenderModal;
						return (
							<OriginalModal
								{...props}
								onActionPerformed={(items) => {
									props.onActionPerformed?.(items);
									refresh();
								}}
							/>
						);
					},
				};
			}),
		[refresh]
	);

	const handleChangeView = useCallback((newView) => {
		setView(newView);
	}, []);

	if (error) {
		return (
			<Notice status="error" isDismissible={false}>
				{error}
			</Notice>
		);
	}

	return (
		<DataViewsComponent
			data={emails}
			fields={fields}
			view={view}
			onChangeView={handleChangeView}
			defaultLayouts={DEFAULT_LAYOUTS}
			actions={actionsWithRefresh}
			paginationInfo={paginationInfo}
			isLoading={isLoading}
			search={true}
			searchLabel={__('Search emails…', 'prc-email-builder')}
			getItemId={(item) => item.id.toString()}
			isItemClickable={() => true}
			onClickItem={(item) => {
				if (item.edit_url) {
					window.location.href = item.edit_url;
				}
			}}
		/>
	);
}
