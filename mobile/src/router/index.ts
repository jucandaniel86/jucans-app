import { createRouter, createWebHistory } from 'vue-router'

import AppLayout from '@/layouts/AppLayout.vue'
import { useAuthStore } from '@/stores/auth'
import HomeView from '@/views/HomeView.vue'
import LoginView from '@/views/LoginView.vue'
import AddRecipeView from '@/views/AddRecipeView.vue'
import AdminIngredientsView from '@/views/AdminIngredientsView.vue'
import AdminRecipeReviewsView from '@/views/AdminRecipeReviewsView.vue'
import RecipeDetailView from '@/views/RecipeDetailView.vue'
import RandomRecipeView from '@/views/RandomRecipeView.vue'
import RecipesView from '@/views/RecipesView.vue'
import ShoppingListView from '@/views/ShoppingListView.vue'
import ShoppingListsView from '@/views/ShoppingListsView.vue'
//diets
import DietsListsView from '@/views/diets/DietsListView.vue'
import DietsEditView from '@/views/diets/DietsEditView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { guestOnly: true },
    },
    {
      path: '/',
      component: AppLayout,
      meta: { requiresAuth: true },
      children: [
        { path: 'shopping-lists', name: 'shopping-lists', component: ShoppingListsView },
        { path: 'diets-lists', name: 'diets-lists', component: DietsListsView },
        { path: 'diets/:id', name: 'diet-edit', component: DietsEditView },
        {
          path: 'shopping-lists/:id',
          name: 'shopping-list-detail',
          component: ShoppingListView,
          meta: { shoppingDetail: true },
        },
        {
          path: 'shopping-list',
          name: 'shopping-list',
          component: ShoppingListView,
          meta: { shoppingDetail: true },
        },
        {
          path: '',
          name: 'home',
          component: HomeView,
        },
        {
          path: 'recipes',
          name: 'recipes',
          component: RecipesView,
        },
        {
          path: 'recipes/create',
          name: 'recipe-create',
          component: AddRecipeView,
        },
        {
          path: 'recipes/random',
          name: 'recipe-random',
          component: RandomRecipeView,
        },
        {
          path: 'recipes/:id/edit',
          name: 'recipe-edit',
          component: AddRecipeView,
        },
        {
          path: 'recipes/:id',
          name: 'recipe-detail',
          component: RecipeDetailView,
        },
        {
          path: 'admin/ingredients',
          name: 'admin-ingredients',
          component: AdminIngredientsView,
          meta: { requiresAdmin: true },
        },
        {
          path: 'admin/recipe-reviews',
          name: 'admin-recipe-reviews',
          component: AdminRecipeReviewsView,
          meta: { requiresAdmin: true },
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

router.beforeEach((to) => {
  const authStore = useAuthStore()

  if (!authStore.hasRestoredSession) {
    return true
  }

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return { name: 'login' }
  }

  if (to.meta.guestOnly && authStore.isAuthenticated) {
    return { name: 'home' }
  }

  if (to.meta.requiresAdmin && !authStore.user?.is_admin) {
    return { name: 'home' }
  }

  return true
})

export default router
