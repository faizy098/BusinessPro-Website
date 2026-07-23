import { Button, Container, Title } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { CheckCircle2 } from 'lucide-react';
import { getSurerankUtmUrl } from '@/global/utils/utm';

const LearnAllDoneCard = () => {
	const docsFallbackUrl = getSurerankUtmUrl(
		'https://surerank.com/docs/',
		'admin_learn',
		'all_done_docs'
	);
	const supportFallbackUrl = getSurerankUtmUrl(
		'https://surerank.com/contact/',
		'admin_learn',
		'all_done_support'
	);

	return (
		<Container
			direction="column"
			className="gap-3 p-4 sm:p-6 bg-brand-background-50 border border-solid border-brand-border-300 rounded-xl"
		>
			<Title
				className="[&_h2]:text-text-primary"
				title={ __( "You're all set!", 'surerank' ) }
				icon={ <CheckCircle2 className="size-4 text-brand-800" /> }
				size="sm"
				description={ __(
					'You’ve completed every recommended setup step. Visit the docs or reach out if you’d like to go deeper.',
					'surerank'
				) }
			/>
			<div className="flex flex-wrap gap-2">
				<Button
					variant="outline"
					size="sm"
					onClick={ () =>
						window.open(
							window?.surerank_globals?.help_link ||
								docsFallbackUrl,
							'_blank',
							'noopener,noreferrer'
						)
					}
				>
					{ __( 'Open Docs', 'surerank' ) }
				</Button>
				<Button
					variant="outline"
					size="sm"
					onClick={ () =>
						window.open(
							window?.surerank_globals?.support_link ||
								supportFallbackUrl,
							'_blank',
							'noopener,noreferrer'
						)
					}
				>
					{ __( 'Contact Support', 'surerank' ) }
				</Button>
			</div>
		</Container>
	);
};

export default LearnAllDoneCard;
