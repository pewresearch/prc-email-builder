import type { EmailListScope } from '../library/types';

interface DataviewAction {
	id: string;
}

/**
 * Keep shell Edit / View / Trash. Hide View on transactional lists
 * because that post type is not public.
 *
 * @param actions Incoming shell actions.
 * @param scope   Current email list scope, or null off email screens.
 * @return Composed actions for the current list.
 */
export function composeEmailActions<T extends DataviewAction>(
	actions: T[],
	scope: EmailListScope | null
): T[] {
	if (!scope) {
		return actions;
	}
	if (scope !== 'txn') {
		return actions;
	}
	return actions.filter((action) => action.id !== 'view');
}
