export default function Heading({
    title,
    description,
    variant = 'default',
    as: Title = 'h2',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    /** h1 where the heading is the page's own title (C12 "Settings"). */
    as?: 'h1' | 'h2';
}) {
    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}>
            <Title
                className={
                    variant === 'small'
                        ? 'mb-0.5 font-display text-heading font-bold'
                        : 'font-display text-title font-bold rtl:leading-snug'
                }
            >
                {title}
            </Title>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </header>
    );
}
