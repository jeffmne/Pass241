/**
 * Palette et rythme de l'application agent (section 70).
 *
 * Le systeme de design de la plateforme s'applique — bleu azurin, blanc casse,
 * Manrope et Inter — mais SANS le verre liquide. Le verre suppose qu'on regarde
 * un ecran pose, dans une lumiere choisie ; l'agent tient son telephone a bout
 * de bras, la nuit, sous un projecteur ou sous rien du tout. La regle 70.4
 * interdit deja le verre sur les surfaces denses : ici c'est toute
 * l'application qui l'est.
 *
 * Les trois couleurs de verdict sont les plus importantes du fichier. Elles
 * doivent se distinguer AVANT que le texte ne se lise (section 27.8) : vert,
 * orange, rouge, saturees, sur des aplats pleine largeur.
 *
 * Aucun emoji nulle part (regle 80.6), y compris dans les etats de connexion —
 * la plateforme les note en pastilles de couleur, pas en pictogrammes.
 */

export const colors = {
  azure50: '#f2f8fe',
  azure100: '#e4f1fd',
  azure300: '#7db6ef',
  azure500: '#2f8fe6',
  azure600: '#1d6fc4',
  azure700: '#14549b',

  ink: '#10131a',
  canvas: '#f7f9fc',
  white: '#ffffff',

  grey200: '#e1e5eb',
  grey400: '#9aa4b2',
  grey500: '#63707f',

  success: '#1fae68',
  warning: '#f2a93b',
  danger: '#e5484d',
} as const;

/** Fond plein du verdict, lisible d'un metre (section 27.8). */
export const verdictColors = {
  valid: colors.success,
  used: colors.warning,
  invalid: colors.danger,
} as const;

export const spacing = {
  xs: 4,
  sm: 8,
  md: 16,
  lg: 24,
  xl: 32,
} as const;

export const radius = {
  sm: 10,
  md: 16,
  lg: 24,
  pill: 999,
} as const;

export const typography = {
  /** Verdict : le seul texte que l'agent doit pouvoir lire sans s'approcher. */
  verdict: 30,
  title: 22,
  body: 16,
  small: 13,
  tiny: 11,
} as const;
